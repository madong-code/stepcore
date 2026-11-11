# 06 · 回退与状态机兜底

回退 / 跳转 / 重放共用同一条链路：`Engine::rollback()`，由传入的 `RollbackPolicyInterface::mode()` 区分六类模式。宿主在**单事务**内调用 `compensate()` 完成令牌/任务/日志/计数补偿，引擎不负责落库。

## 六类模式

| 模式 | 是否移动令牌 | 目标解析 | 边界守卫 |
|------|--------------|----------|----------|
| `step_back` 逐步回退 | 是 | 逆序查变迁日志最近一次 `result=1` 的非回退 from_node_key | 需 reason / 同日 / 次数上限 |
| `jump` 任意跳转 | 是 | 用 `targetNodeKey`（必须存在且合法） | 需 reason / 同日 / 次数上限 |
| `rework` 返工 | 是 | `origin.properties['rework_to_node_key']` 或首条 `mode=rework` 出边 | 需 reason / 同日 / 次数上限 |
| `reversal` 撤销 | 否（回当前节点） | 当前 `fromNodeKey` | 由策略层 `can()` 控制 |
| `replay` 重放 | 否 | 不移动（幂等返回首次结果） | 由策略层 `can()` 控制 |
| `cancel` 取消 | 否 | 不移动 | 实例/任务终态 |

> `STATIC_MODES = [reversal, replay, cancel]`：解析器直接返回空目标，令牌位置不变。

## 契约 `RollbackPolicyInterface`

```php
interface RollbackPolicyInterface
{
    public function mode(): string;                       // 六模式之一
    public function can(RollbackContext $ctx, string $reason): bool;  // 边界守卫
    public function resolveTarget(RollbackContext $ctx): string;       // 目标 node_key（静态模式返回 ''）
    public function compensate(RollbackContext $ctx): FlowResult;      // 补偿（宿主单事务内调用）
}
```

宿主可实现该接口替换任意模式；内核零改动。默认 `RollbackPolicy` 已覆盖六模式，注册到 `ExtensionRegistry::rollbackPolicies` 即可。

## 默认 `RollbackPolicy`

构造：`new RollbackPolicy($mode, $resolver = null)`（`$resolver` 缺省内部 new `RollbackResolver`）。

`can($ctx, $reason)`：

- `$ctx->mode !== $this->mode` 或 `$ctx->archived`（已归档）→ `false`。
- `REASON_REQUIRED = [step_back, jump, cancel]`：reason 为空 → `false`。
- `!$ctx->hasPermission` → `false`。
- `GUARDED_MODES = [step_back, jump, rework]`：需 `$ctx->sameDay`；且 `$ctx->maxReworkTimes > 0` 时 `$ctx->reentryCount < $ctx->maxReworkTimes`。

`compensate($ctx)` 按模式分发：

| 模式 | 返回 |
|------|------|
| `replay` | `FlowResult::replay(straight(fromNodeKey))` |
| `cancel` | `FlowResult::canceled(reasonCode)` |
| `reversal` | `FlowResult::rollback(fromNodeKey, 'reversal')` |
| 其它 | `FlowResult::rollback(resolveTarget(ctx), mode)` |

## `RollbackContext`

由宿主从 `FlowContext` 组装的不可变上下文：

| 字段 | 类型 | 说明 |
|------|------|------|
| `origin` | `FlowContext` | 原始上下文（含 `graph`） |
| `mode` / `reasonCode` | `string` | 模式 / 原因码 |
| `targetNodeKey` / `fromNodeKey` | `string` | jump 目标 / 当前节点 |
| `reentryCount` / `maxReworkTimes` | `int` | 重入计数 / 次数上限 |
| `sameDay` / `hasPermission` / `archived` | `bool` | 边界标志 |
| `history` | `list<array>` | 该实例最近变迁日志（新→旧），每行含 `result`/`mode`/`from_node_key` |

便捷：`RollbackContext::from(FlowContext $ctx, array $history)` 自动从 `ctx->rollbackMode` / `ctx->reasonCode` / `ctx->targetNodeKey` / `ctx->nodeKey` 派生；`with($overrides)` 派生副本。

## `RollbackResolver`（目标解析）

`resolve(FlowGraph $graph, RollbackContext $ctx): string`

- `STATIC_MODES` → `''`。
- `jump`：`targetNodeKey` 必须非空且 `$graph->has()`，否则 `mes_flow_rollback_target_invalid`。
- `step_back`：逆序遍历 `history`，取首个 `result===1` 且 `mode` 非 `rollback/jump/cancel` 的 `from_node_key`（且图内存在）；无则 `mes_flow_rollback_no_source`。
- `rework`：`origin.properties['rework_to_node_key']`（图内存在）→ 否则首条 `mode=rework` 出边；无则 `mes_flow_rollback_no_rework_edge`。
- 未知模式 → `mes_flow_rollback_mode_unknown:<mode>`。

## `Engine` 链路

```php
public function rollback(RollbackContext $ctx, RollbackPolicyInterface $policy): FlowResult
{
    if (!$policy->can($ctx, $ctx->reasonCode)) {
        return FlowResult::blocked('mes_flow_rollback_denied');
    }
    $this->events->publish(new FlowEvent(FlowEvent::ROLLBACK_BEFORE, $ctx->origin->instanceId, $ctx->fromNodeKey, [...]));
    $result = $policy->compensate($ctx);
    $this->events->publish(new FlowEvent(FlowEvent::ROLLBACK_AFTER, $ctx->origin->instanceId, $ctx->fromNodeKey, ['target' => $result->toNodeKey]));
    return $result;
}
```

`jump()` / `replay()` 直接委托 `rollback()`，区别仅在传入策略的 `mode()`。

## 状态机兜底（`FlowStateMachine`）

回退不仅受策略层 `can()` 约束，还要过状态层白名单：

- **正向白名单** `forward`：`instance` / `task` / `token` 各自允许的状态迁移。
- **逆向白名单** `reverse`（仅回退授权时允许）：
  - `instance`: `FINISHED→RUNNING`, `CANCELED→RUNNING`
  - `task`: `FINISHED→RUNNING`, `CANCELED→RUNNING`
  - `token`: `CONSUMED→ACTIVE`
- `ensure($object, $from, $to)`：非正向白名单 → `StateException`。
- `ensureReverse($object, $from, $to, $authorized)`：`$authorized` 来自 `RollbackPolicy::can()`；`authorized && canReverse` 才放行，否则 `StateException`。

> 终态实例与已归档实例在策略层已拒绝（`archived` / `FINISHED` 无逆向），状态机再做兜底。详见 [07-state-machine.md](07-state-machine.md)。

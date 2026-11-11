# 12 · 宿主装配范式

本页给出宿主（如 mes）把 `stepcore` 内核装配成可用引擎的**通用范式**。具体 mes 实现与守卫职责表见宿主 `download/mes/resource/doc/06-过站引擎核心.md`，此处只描述「任何宿主都应怎么做」。

## 1. 装配 `Engine`

`Engine` 构造需要三个运行时依赖 + 一份「动作 → 守卫名单」映射：

```php
use madong\stepcore\Engine;
use madong\stepcore\action\ActionRegistry;
use madong\stepcore\guard\GuardRegistry;
use madong\stepcore\event\EventBus;

$guards  = new GuardRegistry();
$guards->register(new IdempotencyGuard(/* 带构造依赖 */));
$guards->register(new WoGuard($woService));
$guards->register(new UnitGuard($unitService));
// ... 其余守卫

$actions = new ActionRegistry();
$actions->register(new PassAction($deps));
$actions->register(new TransferAction($deps));
// ...

$events  = new EventBus($publisher);   // IEventPublisher 实现

$engine = new Engine(
    $guards,
    $actions,
    $events,
    [
        'pass'     => ['idempotency', 'wo', 'unit'],
        'transfer' => ['wo', 'qty', 'load', 'qc'],
        // ...
    ],
);
```

> **为什么要硬编码装配，而不是 name=>class 映射？** 守卫/动作通常带构造依赖（宿主服务、配置），无法用纯类名泛化实例化。`config/engine.php` 里的 `services` 映射更适合作为「实现清单 + 未来容器接入预留」，运行期行为由实际 `new` 出的实例决定。

## 2. 提供宿主端口（`interface/services/I*Service`）

内核通过以下端口访问宿主能力，宿主必须实现：

| 端口 | 用途 |
|------|------|
| `IEventPublisher` | 事务后事件发布（MQ / outbox） |
| `IIdempotencyStore` | 幂等占位（`acquire`/`commit`/`get`/`release`） |
| `IRouteGraphService` | `route_id → RouteGraph`（可含 revision 缓存） |
| `IWorkOrderService` | 工单只读快照 |
| `IWipCardService` | 流转卡只读快照 |
| `ITraceUnitService` | 追溯单元只读快照 |
| `IStationLoadService` | 站荷只读快照 |
| `IFlowLogService` | 流转日志写入 |

这些端口**只在宿主实现的动作 / 守卫内部**被调用，内核本身不依赖它们——这正是「算法在包内、业务在宿主」的分界。

## 3. 一次过站请求的完整时序

```
┌─ 宿主门面（如 TransitService）
│  1. 解析请求 → 组装 FlowContext（注入 unit/card/extra 等快照）
│  2. 取图（IRouteGraphService / FlowGraphParser）→ FlowGraph
│  3. 把 graph 挂到 ctx.graph（回退解析器需要）
│  4. begin transaction
│  5.   $result = $engine->flow($ctx)
│  6.   if ($result->type === 'blocked') → 回滚/返回业务错误
│  7.   否则按 $result 落库（位置/数量/令牌/任务/日志）
│  8. commit
│  9.   $engine->events()->flush()    // 事务提交后发事件
└─ on exception → rollback; $engine->events()->clear()
```

## 4. 回退 / 跳转 / 重放

```php
use madong\stepcore\rollback\RollbackContext;
use madong\stepcore\rollback\RollbackPolicy;

$ctx = RollbackContext::from($flowContext, $history);   // 自动派生 mode/reason/target/fromNode
$policy = new RollbackPolicy('step_back');              // 或 'jump'/'rework'/'reversal'/'replay'/'cancel'
// 也可用宿主实现的 RollbackPolicyInterface 替换任意模式

begin transaction
  $result = $engine->rollback($ctx, $policy);
  if ($result->type === 'blocked') → 回滚
  按 $result->toNodeKey 补偿令牌/任务/日志（单事务内）
commit
  $engine->events()->flush();
```

## 5. 扩展注册（插件化）

各插件声明 manifest（如 `config/flow_extension.php`），宿主扫描后统一 `ExtensionRegistry::discover()`：

```php
$reg = new ExtensionRegistry();
$reg->discover($manifests);              // 来自各插件的 manifest 数组
// 然后把 bucket 注入到路由解析 / 条件求值 / 回退解析等组件
$branch = $reg->branchStrategies()['hash'] ?? new StableHashBranchStrategy();
$resolver = new FlowTransitionResolver($conditionEvaluator, $branch);
```

新增节点类型 / 条件函数 / 分支策略 / 回退策略 / 事件监听 / 切面 / 模块 = 实现对应契约 + manifest 声明，**引擎零改动**。

## 6. 灰度与回滚（推荐做法）

- 双轨图并存：`RouteGraph`（v1，seq 索引 + 隐式顺延）与 `FlowGraph`（v2，node_key 显式图），切流完成前互不替代。
- 影子比对：`FlowResult::toLegacyArray()` 兼容旧 `resolveNext` 输出，新旧路径结果 diff 落日志，不改返回值。
- 收敛条件（参考）：shadow diff 连续归零 + legacy/v2 双路径回归全绿 + 压测无退化，才可切默认 v2 并移除 legacy。
- 保留 `v2=off` 回滚开关与 shadow 安全阀，异常时可快速回落。

## 7. 常见坑

| 现象 | 根因 | 对策 |
|------|------|------|
| `mes_flow_guard_not_registered:xxx` | 守卫未 `register` 或名单拼错 | 启动期 `GuardRegistry::assertRegistered` 已 fail-fast |
| 守卫 `check` 抛异常被吞 | 守卫未捕获内部异常 | `GuardChain` 会转 `error` 并阻断，仍需守卫自身健壮 |
| 隐式 seq 顺延在 v2 失效 | v2 无 `nextSeqInOrder`，需显式 straight 边 | v1 回填时 `FlowGraphParser::fromV1` 已物化 |
| 事件「幽灵发出」 | 在事务内 `flush()` | 严格在 commit 后 `flush()`，异常 `clear()` |
| 同 SN 走不同支 | 分支种子不一致 | `FlowContext::branchSeed()` 默认 `unitNo`，保证同种子恒定同支 |
| 幂等不生效 | `IdempotencyGuard` 无 `idempotencyKey` 透传 + `IIdempotencyStore` 缺 `commit` | 先补齐提交与 replay 回放链路再启用引擎幂等 |

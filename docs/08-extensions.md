# 08 · 扩展注册表与扩展点

内核「开闭原则」的核心：`ExtensionRegistry` 扫描各插件声明的 manifest，统一注册全部扩展；新增能力**零改引擎**。宿主负责扫描插件目录并把 manifest 数组传入（内核不依赖 Webman / 数据库）。

## `ExtensionRegistry`

```php
$reg = new ExtensionRegistry();
$reg->discover([
    // 每个插件的 config/flow_extension.php 返回值
    [
        'node_types'        => ['step' => ['property_schema' => [...], 'handler' => StepHandler::class, 'label' => '工序']],
        'conditions'        => ['qty_below' => QtyBelowFn::class],
        'guards'            => ['wo' => WoGuard::class],
        'actions'           => ['pass' => PassAction::class],
        'rollback_policies' => ['step_back' => StepBackPolicy::class],
        'branch_strategies' => ['hash' => StableHashBranchStrategy::class],
        'listeners'         => ['kpi' => KpiListener::class],
        'aspects'           => [SkillAspect::class],
        'modules'           => [AuditModule::class],
    ],
]);
// 各 bucket 访问器
$reg->nodeTypes();          // NodeTypeRegistry
$reg->conditions();         // array<key, ConditionFn>
$reg->guards();             // array<name, GuardInterface>
$reg->actions();            // array<name, ActionInterface>
$reg->rollbackPolicies();   // array<name, RollbackPolicyInterface>
$reg->branchStrategies();   // array<name, BranchStrategyInterface>
$reg->listeners();          // array<name, FlowEventListener>
$reg->aspects();            // list<AspectInterface>（已按 priority 升序）
$reg->modules();            // list<FlowModule>
```

- `make($classOrInstance, $contract)`：接受已实例化的契约对象，或字符串类名 `new` 后校验契约；缺失类 / 契约不符 → `ConfigurationException('mes_flow_extension_class_missing' / 'contract_mismatch')`。
- `aspects` 在 `discover()` 末尾按 `priority()` 升序排好。

## 扩展契约一览

| 扩展 | 契约 | 注册键 | 说明 |
|------|------|--------|------|
| 节点类型 | `NodeTypeHandler` | `node_types[code]` | 见下；含 `property_schema` 与 `meta` |
| 条件函数 | `ConditionFn` | `conditions[key]` | 条件 DSL 白名单函数 |
| 守卫 | `GuardInterface` | `guards[name]` | 前置校验 |
| 动作 | `ActionInterface` | `actions[name]` | 业务执行单元 |
| 回退策略 | `RollbackPolicyInterface` | `rollback_policies[name]` | 六模式之一 |
| 分支策略 | `BranchStrategyInterface` | `branch_strategies[name]` | 确定性分叉 |
| 事件监听 | `FlowEventListener` | `listeners[name]` | 订阅 `FlowEvent` |
| 切面 | `AspectInterface` | `aspects[]` | 优先级切面 |
| 模块 | `FlowModule` | `modules[]` | 生命周期模块 |

## 节点类型 `NodeTypeHandler`（零改引擎新增节点类型）

```php
interface NodeTypeHandler
{
    public function onEnter(FlowContext $ctx): void;     // 令牌抵达：校验 properties、建任务/历史快照
    public function onExecute(FlowContext $ctx): FlowResult; // 节点执行：过站/路由/分叉汇合
    public function onLeave(FlowContext $ctx): void;     // 令牌离开：归档任务、写变迁日志
}
```

注册中心 `NodeTypeRegistry`：

- `register($code, $propertySchema, $handler, $meta=[])`：重复 code → `mes_flow_node_type_duplicated:<code>`；空 code → `mes_flow_node_type_code_required`。
- `has($code)` / `handler($code)`（未知 → `mes_flow_node_type_unknown:<code>`）/ `propertySchema($code)`。
- `describe()`：返回 `{code, property_schema, ...meta}`，供设计器元信息下发（如 `GET /mes/route/designer/meta`）。
- `count()`。

新增节点类型 = 提供 `NodeTypeHandler` 实现 + `propertySchema` + manifest 声明，**引擎零改动**。

## 条件函数 `ConditionFn`

```php
interface ConditionFn
{
    public function key(): string;                       // 白名单键
    public function evaluate(FlowContext $ctx, array $args): bool;
}
```

核心不做业务判断，所有业务条件由宿主扩展。配合 `ConditionEvaluator` 的 `rules` 使用（见 [03-routing.md](03-routing.md)）。

## 分支策略 `BranchStrategyInterface`

```php
interface BranchStrategyInterface
{
    /** @param list<FlowEdge> $edges 候选 branch 出边 @return list<FlowEdge> 命中的出边 */
    public function choose(array $edges, string $seed): array;
}
```

默认 `StableHashBranchStrategy` 以 `seed`（通常 SN）稳定哈希按 `weight` 选支，同种子恒定同支。

## 事件监听 `FlowEventListener`

```php
interface FlowEventListener
{
    public function onEvent(FlowEvent $event): void;
}
```

订阅 `FlowEventBus`（进程内，事务提交后 `flush` 分发）。事件不承担归档正确性，仅用于派生（看板、站荷、KPI）。

## 切面 `AspectInterface`

```php
interface AspectInterface
{
    public function priority(): int;
    public function before(FlowContext $ctx): bool;       // false → 中断后续评估
    public function after(FlowContext $ctx, FlowResult $result): void;
    public function onError(FlowContext $ctx, \Throwable $e): void;
}
```

`AspectDispatcher` 按 `priority` 升序执行 `before → 业务 → after`，异常走 `onError`；任一 `before()` 返回 `false` 即中断。`JoinPoint` 提供切点常量（process/task/transition/error 等）供参考。

## 模块 `FlowModule`（生命周期）

```php
interface FlowModule
{
    public function register(ExtensionRegistry $registry): void;
    public function boot(): void;
    public function shutdown(): void;
}
```

`ModuleManager` 负责 `register → boot → shutdown` 生命周期（幂等 `booted` 标记），可追加非 manifest 注入的模块（`add()`）。宿主在启动/停止时调用对应阶段。

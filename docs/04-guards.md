# 04 · 守卫链

守卫在**动作执行前**评估前置条件，任一 `block` 即中断动作。`Engine::flow()` 按构造时传入的 `guardNames[action]` 取名单，跑 `GuardChain` 并短路。

## 契约 `GuardInterface`（`contracts/GuardInterface.php`）

```php
interface GuardInterface
{
    public function name(): string;        // 注册名（与 guardNames 名单对应）
    public function priority(): int;        // 数值越小越先执行
    public function check(FlowContext $ctx): bool;  // false = 阻断该变迁
}
```

> `check()` 返回 `bool` 是 v2 契约；`src/guard/GuardInterface.php` 为 legacy 链（返回 `GuardOutcome`）并存，切流完成后合并。新实现请基于 `contracts\GuardInterface`，在 `check()` 内自行构造 `GuardOutcome` 风格的阻断信息并返回 `false`，阻断明细由 `Engine` 从 `GuardReport` 读取。

## `GuardChain`（执行语义）

`run(FlowContext $ctx, bool $collectAll = false): GuardReport`

- 按 `priority()` **升序**执行（GC-001）。
- **首个 block 短路**，不执行后续守卫（GC-002）；`collectAll=true` 时不短路，聚合全部 block 明细（GC-004，设计器/审计场景）。
- `warn` 聚合**不阻断**（GC-003）。
- 守卫抛异常 → 转为 `GuardOutcome::error(...)`，**不吞**（GC-005）。

## `GuardRegistry`

- `register(GuardInterface)` / `has($name)` / `get($name)` / `all()`。
- `chain(array $names): GuardChain`：按名单构建链（链内顺序与名单无关，统一按 priority 排）。
- `assertRegistered(array $names)`：启动期 fail-fast，未注册名 → 抛 `ConfigurationException('mes_flow_guard_not_registered:<missing>')`（GC-006）。

## `GuardReport` / `GuardOutcome`

`GuardOutcome` 状态：`ok` / `warn` / `block` / `error`；`blocked()` = `block|error`。

`GuardReport` 字段：`executed`（全量执行明细，含 name）/`blocks`/`warnings`/`shortCircuited`；方法：`passed()`（无 block/error）/`firstBlock()`/`toArray()`。

## 阻断结果如何回到调用方

`Engine::flow()` 在 `!report->passed()` 时构造：

```php
new FlowResult(
    'blocked',
    errorCode:  $block?->errorCode ?? 'mes_flow_guard_blocked',
    errorDetail: $block?->detail,
    warnings:   $this->flattenWarnings($report),   // 所有 warn 明细并入
);
```

放行时守卫产生的 `warnings` 也会合并进动作返回的 `FlowResult`。

## 命名约定（宿主侧参考）

守卫名单在 `Engine` 构造时由宿主传入 `action => [guard names]`。mes 的实际装配（硬编码，因守卫多带构造依赖）与完整守卫职责表见宿主 `06-过站引擎核心.md` §3。内核只消费「名字 + 优先级 + check 结果」，不关心守卫内部逻辑。

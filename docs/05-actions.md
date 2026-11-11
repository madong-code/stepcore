# 05 · 动作

动作是守卫链**放行后**执行的业务单元（写数量 / 位置 / 日志 / 令牌迁移等）。`Engine::flow()` 通过 `ActionRegistry::get($ctx->action)` 取出并执行。

## 契约 `ActionInterface`（`contracts/ActionInterface.php`）

```php
interface ActionInterface
{
    public function name(): string;                 // 与 FlowContext.action 对应
    public function handle(FlowContext $ctx): FlowResult;
}
```

`handle()` 返回 `FlowResult`；`Engine` 会把它与守卫链产生的 `warnings` 合并（保留动作自身 warnings 并追加守卫 warnings）。

## `ActionRegistry`

```php
$actions = new ActionRegistry();
$actions->register(new PassAction());     // 以 name() 为键
$actions->has('pass');                    // bool
$actions->get('pass');                    // 未注册 → ConfigurationException('mes_flow_action_not_registered:pass')
$actions->all();                          // array<name, ActionInterface>
```

`Engine` 构造时注入已装配好的 `ActionRegistry`，因此宿主可在此处完成所有动作实现与依赖注入（动作往往需要宿主服务，无法用 name=>class 映射泛化实例化）。

## 与守卫的协作

```
action 名单（构造时传入 Engine）:
  'pass'     => ['idempotency','wo','unit','intercept','duplicate_scan','skill','step_hook']
  'transfer' => ['wo','qty','load','first_article','qc','skill','join','outsource','step_hook']
  'report_ng'=> ['wo','unit','step_hook']
  ...
```

动作与守卫的解耦点：守卫只做「能否执行」的判断（读态、无副作用），动作做「执行」与「写态」。所有写态操作由宿主在 `handle()` 内落到存储，**建议在宿主单事务内**完成，以便与 `EventBus::flush()` 对齐提交边界。

## 新增动作的步骤（宿主侧）

1. 实现 `contracts\ActionInterface`（实现 `name()` / `handle()`）。
2. 在宿主装配处实例化并 `ActionRegistry::register()`。
3. 在 `Engine` 构造的 `guardNames` 中为该动作追加守卫名单（如需）。

> 注：内核只负责「取动作 → 跑 → 合并 warnings」。动作内部的业务规则、持久化、幂等完全由宿主实现，`stepcore` 不提供任何默认动作。

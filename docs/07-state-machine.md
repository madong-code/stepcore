# 07 · 状态机

内核提供两类状态机，均为**显式白名单**——非法迁移直接抛 `StateException`，绝不静默。

## 通用 `StateMachine`（`state/StateMachine.php`）

最通用形态：`from => [允许的 to...]`。

```php
$sm = new StateMachine([
    'pending'  => ['running', 'canceled'],
    'running'  => ['finished', 'canceled'],
    'finished' => [],          // 终态
]);

$sm->can('pending', 'running');   // true
$sm->ensure('pending', 'finished'); // 抛 StateException(mes_flow_state_invalid: pending -> finished)
```

- `can($from, $to)`：`from`/`to` 接受 `string|int|null`。
- `ensure($from, $to, $event='')`：非法迁移抛 `StateException`（错误信息含 `from/to/event`）。

适用于任意自定义状态域（如工单态、单元态）的校验，宿主可直接复用。

## 业务状态机 `FlowStateMachine`（`state/FlowStateMachine.php`）

针对**实例 / 任务 / 令牌**三套内置状态，分别维护正向白名单与逆向白名单。

### 状态常量

| 对象 | 常量 |
|------|------|
| instance | `RUNNING=1` / `FINISHED=2` / `SUSPENDED=3` / `CANCELED=4` |
| task | `PENDING=1` / `RUNNING=2` / `FINISHED=3` / `CANCELED=4` / `SUSPENDED=5` |
| token | `ACTIVE=1` / `WAITING_JOIN=2` / `CONSUMED=3` |

### 正向白名单 `forward`

```text
instance: RUNNING   → {FINISHED, SUSPENDED, CANCELED}
           SUSPENDED → {RUNNING, CANCELED}
           FINISHED  → {}          (终态)
           CANCELED  → {}          (终态)
task:     PENDING   → {RUNNING, CANCELED}
           RUNNING   → {FINISHED, CANCELED, SUSPENDED}
           SUSPENDED → {RUNNING, CANCELED}
           FINISHED  → {}          (终态)
           CANCELED  → {}          (终态)
token:    ACTIVE       → {CONSUMED, WAITING_JOIN}
           WAITING_JOIN → {CONSUMED}
           CONSUMED     → {}       (终态)
```

### 逆向白名单 `reverse`（回退专用，必须显式授权）

```text
instance: FINISHED → {RUNNING}    CANCELED → {RUNNING}
task:     FINISHED → {RUNNING}    CANCELED → {RUNNING}
token:    CONSUMED → {ACTIVE}
```

### API

| 方法 | 说明 |
|------|------|
| `can($object, $from, $to)` | 正向白名单查询 |
| `canReverse($object, $from, $to)` | 逆向白名单查询 |
| `ensure($object, $from, $to, $event='')` | 正向校验，非法抛 `StateException` |
| `ensureReverse($object, $from, $to, $authorized, $event='rollback')` | 逆向校验，`$authorized` 由 `RollbackPolicy::can()` 传入；`authorized && canReverse` 才放行 |

> 回退时先过策略层（`RollbackPolicy::can()`），再过状态层（`ensureReverse`）。实例终态 / 已归档在策略层已拒绝，状态机仅做兜底。详见 [06-rollback.md](06-rollback.md)。

## `StateException`

```php
class StateException extends StepCoreException
{
    public function __construct(public string $from, public string $to, public string $event = '')
}
```

错误信息格式：`mes_flow_state_invalid: <from> -> <to> (event=<event>)`，便于日志与影子比对定位非法迁移。

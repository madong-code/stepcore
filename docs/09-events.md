# 09 · 事件

内核提供**两种**事件总线，定位不同：

| 总线 | 类 | 用途 | 分发时机 |
|------|----|------|----------|
| 事务后发布总线 | `EventBus` | 经 `IEventPublisher` 发往外部（MQ / 看板 / 审计），**绝早于事务提交** | `flush()` 在事务提交后调用 |
| 进程内订阅总线 | `FlowEventBus` | 宿主进程内监听器（派生态计算） | `flush()` 在事务提交后分发 |

`Engine` 自身使用 `EventBus`（构造注入），在其 `flow()` / `rollback()` 中 `publish()` 入队 `FlowEvent`，由宿主在事务提交后 `flush()`、异常时 `clear()`。

## `FlowEvent`（值对象）

```php
class FlowEvent
{
    public function __construct(
        public readonly string $name,
        public readonly string $instanceId = '',
        public readonly string $nodeKey = '',
        public readonly array $payload = [],
    );
}
```

事件**不含整图、不含 PII**（仅标量 payload）。事件名常量：

| 常量 | 值 |
|------|----|
| `START` | `flow.start` |
| `END` | `flow.end` |
| `ROLLBACK_BEFORE` / `ROLLBACK_AFTER` | `flow.rollback.before` / `flow.rollback.after` |
| `NODE_ENTER` / `NODE_LEAVE` / `NODE_REENTER` | `node.enter` / `node.leave` / `node.reenter` |
| `TASK_CREATE` / `TASK_COMPLETE` / `TASK_CANCEL` | `task.create` / `task.complete` / `task.cancel` |
| `TRANSITION_BEFORE` / `TRANSITION_AFTER` / `TRANSITION_BLOCKED` | `transition.before` / `transition.after` / `transition.blocked` |
| `ERROR` | `error` |

## `EventBus`（事务后发布）

```php
$bus = new EventBus($publisher);   // $publisher: IEventPublisher
$bus->publish($event);              // 仅入队
$bus->pending();                    // 当前待发列表
$count = $bus->flush();            // 提交后调用：经 IEventPublisher 发出，返回条数
$bus->clear();                     // 事务回滚时调用：清空
```

- `publish()` 接受 `object | array`；`flush()` 才真正调用 `IEventPublisher::publish($events)`。
- **事件不早于提交发出**：避免事务回滚后产生幽灵事件；派发异常只记日志、不回滚业务。

### 宿主端口 `IEventPublisher`

```php
interface IEventPublisher
{
    /** @param list<object|array<string,mixed>> $events */
    public function publish(array $events): void;
}
```

宿主实现可对接消息队列 / 领域事件 outbox（保证「事务提交 + 事件发出」最终一致）。

## `FlowEventBus`（进程内订阅）

```php
$local = new FlowEventBus();
$local->subscribe('task.complete', $listener);  // $listener: FlowEventListener
$local->subscribe('*', $wildcardListener);      // 通配
$local->publish($event);                        // 入队
$local->flush();                                // 提交后分发：先匹配事件名，再匹配 '*'
$local->clear();                                // 回滚清空
```

- 订阅键支持具体事件名与 `'*'` 通配。
- 事件**不承担归档正确性**，仅用于派生（站荷更新、看板推送、KPI）。真正落库由动作在宿主事务内完成。

## 事务边界约定（宿主必须遵守）

```
begin transaction
  ├─ action.handle(ctx)  写数量/位置/日志/令牌
  ├─ engine 已 publish 事件入队（未发出）
commit
  ├─ eventBus.flush()    发出 / 分发事件
on exception
  ├─ rollback
  └─ eventBus.clear()    丢弃未发事件
```

> 非法状态迁移 / 未注册扩展等配置问题由 `ConfigurationException` / `StateException` 在事务内抛出，回滚即可，不会产生幽灵事件。

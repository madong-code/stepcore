<?php
declare(strict_types=1);

namespace madong\stepcore\event;

use madong\stepcore\interface\services\IEventPublisher;

/**
 * 事务后事件总线（P3-11）：
 * - 动作执行期间 publish() 仅入队；
 * - 事务提交后宿主调用 flush()，才真正经 IEventPublisher 发出（事件不早于提交）。
 */
final class EventBus
{
    /** @var list<object|array<string, mixed>> */
    private array $pending = [];

    public function __construct(private readonly ?IEventPublisher $publisher = null)
    {
    }

    /** @param object|array<string, mixed> $event */
    public function publish(object|array $event): void
    {
        $this->pending[] = $event;
    }

    /** @return list<object|array<string, mixed>> */
    public function pending(): array
    {
        return $this->pending;
    }

    /** 事务提交后调用；返回发布条数 */
    public function flush(): int
    {
        if ($this->pending === []) {
            return 0;
        }
        $events = $this->pending;
        $this->pending = [];
        $this->publisher?->publish($events);
        return count($events);
    }

    /** 事务回滚时清空 */
    public function clear(): void
    {
        $this->pending = [];
    }
}

<?php
declare(strict_types=1);

namespace madong\stepcore\event;

use madong\stepcore\contracts\FlowEventListener;

/** 事务后事件总线：publish 只入队，宿主提交后 flush；回滚 clear。事件不承担归档正确性。 */
final class FlowEventBus
{
    /** @var array<string, list<FlowEventListener>> */
    private array $listeners = [];
    /** @var list<FlowEvent> */
    private array $pending = [];

    public function subscribe(string $eventName, FlowEventListener $listener): void
    {
        $this->listeners[$eventName][] = $listener;
    }

    public function publish(FlowEvent $event): void
    {
        $this->pending[] = $event;
    }

    /** @return list<FlowEvent> */
    public function pending(): array
    {
        return $this->pending;
    }

    /** 事务提交后调用，返回分发条数 */
    public function flush(): int
    {
        if ($this->pending === []) {
            return 0;
        }
        $events = $this->pending;
        $this->pending = [];
        foreach ($events as $event) {
            foreach ([$event->name, '*'] as $key) {
                foreach ($this->listeners[$key] ?? [] as $listener) {
                    $listener->onEvent($event);
                }
            }
        }
        return count($events);
    }

    /** 事务回滚时清空 */
    public function clear(): void
    {
        $this->pending = [];
    }
}

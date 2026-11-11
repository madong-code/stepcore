<?php
declare(strict_types=1);

namespace madong\stepcore\interface\services;

/**
 * 事件发布器（P3-11）：宿主实现 afterCommit/outbox——事件不得早于事务提交发出。
 */
interface IEventPublisher
{
    /** @param list<object|array<string, mixed>> $events */
    public function publish(array $events): void;
}

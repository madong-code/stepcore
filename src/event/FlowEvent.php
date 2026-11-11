<?php
declare(strict_types=1);

namespace madong\stepcore\event;

/** 流程事件值对象（不含整图、不含 PII）。 */
final class FlowEvent
{
    /** @param array<string, scalar|null> $payload */
    public function __construct(
        public readonly string $name,
        public readonly string $instanceId = '',
        public readonly string $nodeKey = '',
        public readonly array $payload = [],
    ) {
    }

    public const START = 'flow.start';
    public const END = 'flow.end';
    public const ROLLBACK_BEFORE = 'flow.rollback.before';
    public const ROLLBACK_AFTER = 'flow.rollback.after';
    public const NODE_ENTER = 'node.enter';
    public const NODE_LEAVE = 'node.leave';
    public const NODE_REENTER = 'node.reenter';
    public const TASK_CREATE = 'task.create';
    public const TASK_COMPLETE = 'task.complete';
    public const TASK_CANCEL = 'task.cancel';
    public const TRANSITION_BEFORE = 'transition.before';
    public const TRANSITION_AFTER = 'transition.after';
    public const TRANSITION_BLOCKED = 'transition.blocked';
    public const ERROR = 'error';
}

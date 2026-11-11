<?php
declare(strict_types=1);

namespace madong\stepcore\state;

use madong\stepcore\exception\StateException;
use madong\stepcore\model\FlowTask;
use madong\stepcore\model\FlowToken;

/**
 * 令牌/任务/实例状态机：正向白名单 + 逆向白名单（回退专用，必须显式授权）。
 * 终态实例与已归档实例一律禁止回退。
 */
final class FlowStateMachine
{
    public const INSTANCE_RUNNING = 1;
    public const INSTANCE_FINISHED = 2;
    public const INSTANCE_SUSPENDED = 3;
    public const INSTANCE_CANCELED = 4;

    /** @var array<string, array<int, list<int>>> */
    private array $forward = [
        'instance' => [
            self::INSTANCE_RUNNING   => [self::INSTANCE_FINISHED, self::INSTANCE_SUSPENDED, self::INSTANCE_CANCELED],
            self::INSTANCE_SUSPENDED => [self::INSTANCE_RUNNING, self::INSTANCE_CANCELED],
            self::INSTANCE_FINISHED  => [],
            self::INSTANCE_CANCELED  => [],
        ],
        'task' => [
            FlowTask::PENDING   => [FlowTask::RUNNING, FlowTask::CANCELED],
            FlowTask::RUNNING   => [FlowTask::FINISHED, FlowTask::CANCELED, FlowTask::SUSPENDED],
            FlowTask::SUSPENDED => [FlowTask::RUNNING, FlowTask::CANCELED],
            FlowTask::FINISHED  => [],
            FlowTask::CANCELED  => [],
        ],
        'token' => [
            FlowToken::ACTIVE       => [FlowToken::CONSUMED, FlowToken::WAITING_JOIN],
            FlowToken::WAITING_JOIN => [FlowToken::CONSUMED],
            FlowToken::CONSUMED     => [],
        ],
    ];

    /** 逆向白名单：仅以下迁移在授权回退时允许 */
    private array $reverse = [
        'instance' => [self::INSTANCE_FINISHED => [self::INSTANCE_RUNNING], self::INSTANCE_CANCELED => [self::INSTANCE_RUNNING]],
        'task'     => [FlowTask::FINISHED => [FlowTask::RUNNING], FlowTask::CANCELED => [FlowTask::RUNNING]],
        'token'    => [FlowToken::CONSUMED => [FlowToken::ACTIVE]],
    ];

    public function can(string $object, int $from, int $to): bool
    {
        return in_array($to, $this->forward[$object][$from] ?? [], true);
    }

    public function canReverse(string $object, int $from, int $to): bool
    {
        return in_array($to, $this->reverse[$object][$from] ?? [], true);
    }

    /** @throws StateException */
    public function ensure(string $object, int $from, int $to, string $event = ''): void
    {
        if (!$this->can($object, $from, $to)) {
            throw new StateException((string) $from, (string) $to, $event);
        }
    }

    /**
     * 回退迁移校验：$authorized 由 RollbackPolicy::can() 结果传入；
     * 实例终态/已归档在策略层已拒绝，此处再做状态层兜底。
     *
     * @throws StateException
     */
    public function ensureReverse(string $object, int $from, int $to, bool $authorized, string $event = 'rollback'): void
    {
        if (!$authorized || !$this->canReverse($object, $from, $to)) {
            throw new StateException((string) $from, (string) $to, $event);
        }
    }
}

<?php
declare(strict_types=1);

namespace madong\stepcore\aop;

/** 切面切点（借鉴 workflow aop/JoinPoint）。 */
final class JoinPoint
{
    public const PROCESS_START = 'process.start';
    public const PROCESS_END = 'process.end';
    public const NODE_PRE_EXECUTE = 'node.preExecute';
    public const NODE_POST_EXECUTE = 'node.postExecute';
    public const NODE_AROUND_EXECUTE = 'node.aroundExecute';
    public const TASK_CREATE = 'task.create';
    public const TASK_COMPLETE = 'task.complete';
    public const TASK_ASSIGN = 'task.assign';
    public const TRANSITION_EXECUTE = 'transition.execute';
    public const TRANSITION_ROLLBACK = 'transition.rollback';
    public const ERROR_HANDLER = 'error.handler';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PROCESS_START, self::PROCESS_END,
            self::NODE_PRE_EXECUTE, self::NODE_POST_EXECUTE, self::NODE_AROUND_EXECUTE,
            self::TASK_CREATE, self::TASK_COMPLETE, self::TASK_ASSIGN,
            self::TRANSITION_EXECUTE, self::TRANSITION_ROLLBACK, self::ERROR_HANDLER,
        ];
    }
}

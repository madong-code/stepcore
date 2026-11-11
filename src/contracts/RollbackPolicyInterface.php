<?php
declare(strict_types=1);

namespace madong\stepcore\contracts;

use madong\stepcore\model\FlowResult;
use madong\stepcore\rollback\RollbackContext;

/** 可插拔回退策略：六类模式之一，见 design/.../12-rollback.md。 */
interface RollbackPolicyInterface
{
    /** reversal | replay | step_back | jump | rework | cancel */
    public function mode(): string;

    /** 边界守卫：时限/权限/次数/状态/原因码/目标合法性 */
    public function can(RollbackContext $ctx, string $reason): bool;

    /** 目标 node_key；reversal/replay/cancel 可返回空串（不移动令牌） */
    public function resolveTarget(RollbackContext $ctx): string;

    /** 令牌/任务/日志/计数补偿，由宿主在单事务内调用 */
    public function compensate(RollbackContext $ctx): FlowResult;
}

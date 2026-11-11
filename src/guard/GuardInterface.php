<?php
declare(strict_types=1);

namespace madong\stepcore\guard;

use madong\stepcore\model\FlowContext;

/**
 * 守卫契约（P3-04）：宿主实现 MES 守卫，包内只负责编排。
 * 结果三种：ok 放行 / warn 警告聚合 / block 阻断（error 由 GuardChain 从异常转换而来）。
 */
interface GuardInterface
{
    /** 守卫名（注册表键，稳定 snake_case） */
    public function name(): string;

    /** 执行优先级，小者先执行（U-BE-GC-001） */
    public function priority(): int;

    public function check(FlowContext $ctx): GuardOutcome;
}

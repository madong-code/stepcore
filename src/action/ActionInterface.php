<?php
declare(strict_types=1);

namespace madong\stepcore\action;

use madong\stepcore\model\FlowContext;
use madong\stepcore\model\FlowResult;

/**
 * 动作处理器契约（P3-06）：守卫链放行后执行的业务动作（写数量/位置/日志）。
 */
interface ActionInterface
{
    public function name(): string;

    public function handle(FlowContext $ctx): FlowResult;
}

<?php
declare(strict_types=1);

namespace madong\stepcore\contracts;

use madong\stepcore\model\FlowContext;
use madong\stepcore\model\FlowResult;

/** 优先级切面；before() 返回 false 即 shouldProceed=false，中断后续评估。 */
interface AspectInterface
{
    public function priority(): int;

    public function before(FlowContext $ctx): bool;

    public function after(FlowContext $ctx, FlowResult $result): void;

    public function onError(FlowContext $ctx, \Throwable $e): void;
}

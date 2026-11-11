<?php
declare(strict_types=1);

namespace madong\stepcore\contracts;

use madong\stepcore\model\FlowContext;
use madong\stepcore\model\FlowResult;

/** v2 可插拔动作：转序/返工/回退/汇流等。 */
interface ActionInterface
{
    public function name(): string;

    public function handle(FlowContext $ctx): FlowResult;
}

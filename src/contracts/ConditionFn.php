<?php
declare(strict_types=1);

namespace madong\stepcore\contracts;

use madong\stepcore\model\FlowContext;

/** 条件 DSL 白名单函数：插件可扩展，核心不做业务判断。 */
interface ConditionFn
{
    public function key(): string;

    /** @param array<string, mixed> $args */
    public function evaluate(FlowContext $ctx, array $args): bool;
}

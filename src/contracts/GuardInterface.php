<?php
declare(strict_types=1);

namespace madong\stepcore\contracts;

use madong\stepcore\model\FlowContext;

/** v2 可插拔守卫（与 src/guard/GuardInterface.php 的 legacy 链并存，切流完成后合并）。 */
interface GuardInterface
{
    public function name(): string;

    /** 数值越小越先执行 */
    public function priority(): int;

    /** false = 阻断该变迁，写 result=2 日志 */
    public function check(FlowContext $ctx): bool;
}

<?php
declare(strict_types=1);

namespace madong\stepcore\contracts;

use madong\stepcore\model\FlowEdge;

/** 确定性分叉策略；默认实现以 unit_no 稳定哈希保证同一 SN 恒定走同一支。 */
interface BranchStrategyInterface
{
    /**
     * @param list<FlowEdge> $edges 候选 branch 出边
     * @return list<FlowEdge> 命中的出边
     */
    public function choose(array $edges, string $seed): array;
}

<?php
declare(strict_types=1);

namespace madong\stepcore\route;

use madong\stepcore\contracts\BranchStrategyInterface;
use madong\stepcore\model\FlowEdge;

/** 默认确定性分叉：以 unit_no 稳定哈希按 weight 选支，同一 SN 恒定走同一支。 */
final class StableHashBranchStrategy implements BranchStrategyInterface
{
    /** @param list<FlowEdge> $edges @return list<FlowEdge> */
    public function choose(array $edges, string $seed): array
    {
        if ($edges === []) {
            return [];
        }
        if (count($edges) === 1) {
            return $edges;
        }
        $weights = [];
        $sum = 0;
        foreach ($edges as $edge) {
            $w = $edge->weight() ?? 0;
            $weights[] = $w;
            $sum += $w;
        }
        if ($sum <= 0) {
            $weights = array_fill(0, count($edges), 100);
            $sum = 100 * count($edges);
        }
        $hit = $seed === '' ? 0 : (int) (sprintf('%u', crc32($seed)) % ($sum * 100));
        $hit /= 100;
        $acc = 0;
        foreach ($edges as $i => $edge) {
            $acc += $weights[$i];
            if ($hit < $acc) {
                return [$edge];
            }
        }
        return [$edges[count($edges) - 1]];
    }
}

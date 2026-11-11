<?php
declare(strict_types=1);

namespace madong\stepcore\route;

use madong\stepcore\enum\TransitionType;
use madong\stepcore\exception\ConfigurationException;
use madong\stepcore\model\FlowResult;
use madong\stepcore\model\RouteEdge;
use madong\stepcore\model\RouteGraph;

/**
 * 转移路由解析器（P3-03，纯函数：图进 → 结果出，无 IO）。
 *
 * 规则（04-test-cases.md §3.3，修复 D4：显式 type=1 直线优先于隐式顺延）：
 * - 无正向出边 → seq 顺延隐式直线 / 尾站（TR-001/003/010，仅返工出边同此）
 * - 有 type=1 → 直达 to_seq（TR-002）
 * - 有 type=2 → 返回全部分支候选（权重预览，不写库，TR-014）
 * - 有 type=3 → 汇合后下一站（TR-009）
 * - 分支选支：SN 种子哈希 × 权重（同种子恒定同支，TR-004/005；权重全空均分 TR-006）
 */
final class TransitionResolver
{
    public function resolve(RouteGraph $graph, int $fromSeq): FlowResult
    {
        $forward = $graph->forwardEdgesFrom($fromSeq);
        if ($forward === []) {
            $next = $graph->nextSeqInOrder($fromSeq);
            return $next === null
                ? FlowResult::tail()
                : FlowResult::straight($next, $graph->node($next)?->toArray());
        }

        $branches = array_values(array_filter($forward, fn (RouteEdge $e) => $e->type === TransitionType::Branch));
        if ($branches !== []) {
            return $this->branchResult($branches, $graph);
        }

        $joins = array_values(array_filter($forward, fn (RouteEdge $e) => $e->type === TransitionType::Join));
        if ($joins !== []) {
            $next = (int) $joins[0]->toSeq;
            return FlowResult::straight($next, $graph->node($next)?->toArray());
        }

        $straights = array_values(array_filter($forward, fn (RouteEdge $e) => $e->type === TransitionType::Straight));
        if ($straights !== []) {
            $next = (int) $straights[0]->toSeq;
            return FlowResult::straight($next, $graph->node($next)?->toArray());
        }

        // 理论不可达：forward 只含 1/2/3
        $next = $graph->nextSeqInOrder($fromSeq);
        return $next === null ? FlowResult::tail() : FlowResult::straight($next, $graph->node($next)?->toArray());
    }

    /**
     * 分支预览（TR-014）：返回全部候选与权重，不做选支。
     * @param list<RouteEdge> $branches
     */
    private function branchResult(array $branches, RouteGraph $graph): FlowResult
    {
        $configured = array_values(array_filter($branches, fn (RouteEdge $e) => $e->weight !== null));
        if ($configured === []) {
            // 权重全空 → 均分（U-BE-RG-005/TR-006）
            $list = array_map(fn (RouteEdge $e) => [
                'seq'     => $e->toSeq,
                'weight'  => 1,
                'process' => $graph->node($e->toSeq)?->toArray(),
            ], $branches);
            return FlowResult::branch($list, count($branches));
        }
        $total = array_sum(array_map(fn (RouteEdge $e) => max(0, (int) ($e->weight ?? 0)), $branches));
        if ($total <= 0) {
            // 显式配了权重但合计为 0（TR-007）
            throw new ConfigurationException('mes_route_topo_weight_zero');
        }
        $list = array_map(fn (RouteEdge $e) => [
            'seq'     => $e->toSeq,
            'weight'  => max(0, (int) ($e->weight ?? 0)),
            'process' => $graph->node($e->toSeq)?->toArray(),
        ], $branches);
        return FlowResult::branch($list, $total);
    }

    /**
     * 分支选支：种子哈希 × 权重（与旧 pickBranch 同算法：crc32 % total，保证影子比对一致）。
     */
    public function pickBranch(FlowResult $branch, string $seed): FlowResult
    {
        $branches = $branch->branches;
        if ($branches === []) {
            throw new ConfigurationException('mes_transfer_branch_missing');
        }
        $weights = array_map(fn ($b) => max(0, (int) ($b['weight'] ?? 0)), $branches);
        $total   = array_sum($weights);
        if ($total <= 0) {
            $weights = array_fill(0, count($branches), 1);
            $total   = count($branches);
        }
        $point = (int) (crc32($seed) % max(1, 10000)) % $total;
        $acc   = 0;
        foreach ($branches as $i => $b) {
            $acc += $weights[$i];
            if ($point < $acc) {
                return new FlowResult('branch', seq: (int) $b['seq'], process: $b['process'], branchKey: (string) $b['seq']);
            }
        }
        $last = $branches[count($branches) - 1];
        return new FlowResult('branch', seq: (int) $last['seq'], process: $last['process'], branchKey: (string) $last['seq']);
    }

    /**
     * NG 自动返工回边解析（TR-011/012）：from_seq 的 type=4 边中条件匹配的第一条。
     * 条件语义：auto_on_ng=true 才回退；defect_codes 空匹配任意不良，否则需包含 defectCode。
     */
    public function resolveRework(RouteGraph $graph, int $fromSeq, string $action = 'report_ng', ?string $defectCode = null): ?int
    {
        if ($action !== 'report_ng') {
            return null;
        }
        foreach ($graph->reworkEdgesFrom($fromSeq) as $edge) {
            $cond = $edge->condition;
            if (!($cond['auto_on_ng'] ?? false)) {
                continue;
            }
            $codes = $cond['defect_codes'] ?? [];
            if (is_array($codes) && $codes !== [] && ($defectCode === null || !in_array($defectCode, $codes, true))) {
                continue;
            }
            return $edge->toSeq;
        }
        return null;
    }
}

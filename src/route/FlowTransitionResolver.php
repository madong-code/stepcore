<?php
declare(strict_types=1);

namespace madong\stepcore\route;

use madong\stepcore\contracts\BranchStrategyInterface;
use madong\stepcore\model\FlowContext;
use madong\stepcore\model\FlowEdge;
use madong\stepcore\model\FlowGraph;
use madong\stepcore\model\FlowResult;
use madong\stepcore\parser\ConditionEvaluator;

/**
 * v2 变迁解析：按 from_node_key 取出边 → trigger 匹配 → condition 求值 → priority 短路。
 * 不再有 nextSeqInOrder 的隐式 seq 顺延（v1 回填时已物化为显式 straight 边）。
 */
final class FlowTransitionResolver
{
    private BranchStrategyInterface $branchStrategy;

    public function __construct(
        private readonly ConditionEvaluator $conditions,
        ?BranchStrategyInterface $branchStrategy = null,
    ) {
        $this->branchStrategy = $branchStrategy ?? new StableHashBranchStrategy();
    }

    public function resolve(FlowGraph $graph, string $fromNodeKey, FlowContext $ctx): FlowResult
    {
        if (!$graph->has($fromNodeKey)) {
            return FlowResult::blocked('mes_flow_node_unknown', $fromNodeKey);
        }
        if ($graph->isTail($fromNodeKey)) {
            return FlowResult::tail();
        }

        $candidates = [];
        foreach ($graph->outEdgesFrom($fromNodeKey) as $edge) {
            if (!$edge->isForward() || $edge->mode === FlowEdge::MODE_REWORK) {
                continue; // 返工回边走 reportNg 专用路径，不参与正常前向解析
            }
            if (!$this->triggerMatches($edge, $ctx)) {
                continue;
            }
            if (!$this->conditions->matches($edge, $ctx)) {
                continue;
            }
            $candidates[] = $edge;
        }
        if ($candidates === []) {
            return FlowResult::blocked('mes_flow_no_transition', $fromNodeKey);
        }

        // FlowGraph 构造时已按 priority 降序 + edge_key 升序排序
        $first = $candidates[0];
        if ($first->mode === FlowEdge::MODE_BRANCH) {
            $branchEdges = array_values(array_filter(
                $candidates,
                fn (FlowEdge $e) => $e->mode === FlowEdge::MODE_BRANCH
            ));
            $chosen = $this->branchStrategy->choose($branchEdges, $ctx->branchSeed());
            if ($chosen === []) {
                return FlowResult::blocked('mes_flow_branch_unresolved', $fromNodeKey);
            }
            $targets = array_map(static fn (FlowEdge $e) => [
                'to_node_key' => $e->toNodeKey,
                'edge_key'    => $e->edgeKey,
                'weight'      => $e->weight(),
                'branch_key'  => $e->edgeKey,
            ], $chosen);
            $total = array_sum(array_map(static fn (array $t) => (int) ($t['weight'] ?? 0), $targets));
            return FlowResult::branchTo($targets, $total);
        }

        return FlowResult::straightTo($first->toNodeKey, $first->edgeKey);
    }

    private function triggerMatches(FlowEdge $edge, FlowContext $ctx): bool
    {
        $trigger = $edge->triggerType();
        if ($ctx->trigger === '' || $ctx->trigger === 'manual') {
            return in_array($trigger, ['manual', 'auto'], true);
        }
        return $trigger === $ctx->trigger || $trigger === 'auto';
    }
}

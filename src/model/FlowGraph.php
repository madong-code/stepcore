<?php
declare(strict_types=1);

namespace madong\stepcore\model;

use madong\stepcore\exception\ConfigurationException;

/**
 * v2 编译图：按 node_key 哈希索引，出边 O(出边数) 取候选。
 * 与 RouteGraph(seq 索引 + 隐式顺延) 并存，切流完成后替代。
 */
final class FlowGraph
{
    /** @var array<string, FlowNode> */
    private array $nodes = [];
    /** @var list<FlowEdge> */
    private array $edges = [];
    /** @var array<string, list<FlowEdge>> */
    private array $outEdges = [];
    /** @var array<string, list<FlowEdge>> */
    private array $inEdges = [];

    /**
     * @param list<FlowNode> $nodes
     * @param list<FlowEdge> $edges
     */
    public function __construct(array $nodes, array $edges)
    {
        foreach ($nodes as $node) {
            if (!$node instanceof FlowNode) {
                throw new ConfigurationException('mes_flow_graph_invalid_node');
            }
            if (isset($this->nodes[$node->nodeKey])) {
                throw new ConfigurationException('mes_flow_node_key_duplicated:' . $node->nodeKey);
            }
            $this->nodes[$node->nodeKey] = $node;
        }

        $seenEdgeKeys = [];
        foreach ($edges as $edge) {
            if (!$edge instanceof FlowEdge) {
                throw new ConfigurationException('mes_flow_graph_invalid_edge');
            }
            if (isset($seenEdgeKeys[$edge->edgeKey])) {
                throw new ConfigurationException('mes_flow_edge_key_duplicated:' . $edge->edgeKey);
            }
            $seenEdgeKeys[$edge->edgeKey] = true;
            $this->edges[] = $edge;
            if (!isset($this->nodes[$edge->fromNodeKey], $this->nodes[$edge->toNodeKey])) {
                throw new ConfigurationException('mes_flow_edge_dangling:' . $edge->edgeKey);
            }
            $this->outEdges[$edge->fromNodeKey][] = $edge;
            $this->inEdges[$edge->toNodeKey][]   = $edge;
        }

        foreach ($this->outEdges as &$list) {
            usort($list, static function (FlowEdge $a, FlowEdge $b): int {
                return [$b->priority(), $a->edgeKey] <=> [$a->priority(), $b->edgeKey];
            });
        }
        unset($list);
    }

    public function node(string $nodeKey): ?FlowNode
    {
        return $this->nodes[$nodeKey] ?? null;
    }

    public function has(string $nodeKey): bool
    {
        return isset($this->nodes[$nodeKey]);
    }

    /** @return array<string, FlowNode> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** @return list<FlowEdge> 已按 priority 降序、edge_key 升序排序 */
    public function outEdgesFrom(string $nodeKey): array
    {
        return $this->outEdges[$nodeKey] ?? [];
    }

    /** @return list<FlowEdge> */
    public function inEdgesTo(string $nodeKey): array
    {
        return $this->inEdges[$nodeKey] ?? [];
    }

    /** @return list<FlowEdge> */
    public function edges(): array
    {
        return $this->edges;
    }

    public function nodeCount(): int
    {
        return count($this->nodes);
    }

    /** 尾节点：无正向出边（取代「最大 seq」）或显式 end 类型 */
    public function isTail(string $nodeKey): bool
    {
        $node = $this->node($nodeKey);
        if ($node === null) {
            return false;
        }
        if ($node->isEnd()) {
            return true;
        }
        // 存在「已定义的前向出边」即不是尾节点；即便该边 enabled=false，
        // 也交给 Resolver 判定为 blocked，而不是误判为流程终点。
        foreach ($this->outEdgesFrom($nodeKey) as $edge) {
            if ($edge->mode === FlowEdge::MODE_REWORK
                || $edge->mode === FlowEdge::MODE_ROLLBACK
                || $edge->mode === FlowEdge::MODE_JUMP
                || $edge->mode === FlowEdge::MODE_CANCEL) {
                continue;
            }
            return false;
        }
        return true;
    }

    public function startNodeKey(): ?string
    {
        foreach ($this->nodes as $key => $node) {
            if ($node->type === 'start') {
                return $key;
            }
        }
        foreach ($this->nodes as $key => $_) {
            if (($this->inEdges[$key] ?? []) === []) {
                return $key;
            }
        }
        return null;
    }

    /** @return list<string> 编译期校验问题码；空数组表示合法 */
    public function validate(): array
    {
        $issues = [];
        $start  = $this->startNodeKey();
        if ($start === null) {
            $issues[] = 'mes_flow_graph_no_start';
            return $issues;
        }
        $seen = [$start => true];
        $queue = [$start];
        while ($queue !== []) {
            $cur = array_shift($queue);
            foreach ($this->outEdgesFrom($cur) as $edge) {
                if (!$edge->isForward() || isset($seen[$edge->toNodeKey])) {
                    continue;
                }
                $seen[$edge->toNodeKey] = true;
                $queue[] = $edge->toNodeKey;
            }
        }
        foreach ($this->nodes as $key => $node) {
            if (!isset($seen[$key]) && !$node->isEnd()) {
                $issues[] = 'mes_flow_graph_unreachable:' . $key;
            }
            if ($node->type === 'join') {
                $branches = array_filter(
                    $this->inEdgesTo($key),
                    fn (FlowEdge $e) => $e->mode === FlowEdge::MODE_BRANCH
                );
                if ($branches === []) {
                    $issues[] = 'mes_flow_graph_join_unpaired:' . $key;
                }
            }
        }
        return $issues;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'nodes'       => array_map(fn (FlowNode $n) => $n->toArray(), array_values($this->nodes)),
            'transitions' => array_map(fn (FlowEdge $e) => $e->toArray(), $this->edges),
        ];
    }
}

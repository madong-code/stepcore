<?php
declare(strict_types=1);

namespace madong\stepcore\model;

use madong\stepcore\enum\TransitionType;
use madong\stepcore\exception\ConfigurationException;

/**
 * 通用路线图（包内，纯数据结构 + 只读查询；P3-01）。
 *
 * 编译产物口径（04-test-cases.md §3.2）：
 * - forward：type=1/2/3 出边；rework：type=4；
 * - 无显式出边时按 seq 升序隐式顺延（U-BE-TR-001）；
 * - 重复 seq 属脏数据，编译即抛 ConfigurationException（U-BE-RG-010）。
 */
final class RouteGraph
{
    /** @var array<int, RouteNode> seq => node（按 seq 升序） */
    private array $nodes;
    /** @var list<RouteEdge> */
    private array $edges;
    /** @var array<int, list<RouteEdge>> */
    private array $outForward;
    /** @var array<int, list<RouteEdge>> */
    private array $outRework;
    /** @var array<int, list<RouteEdge>> */
    private array $inbound;
    /** @var list<int> */
    private array $seqs;

    /**
     * @param array<int, RouteNode> $nodes seq => node
     * @param list<RouteEdge>       $edges
     */
    public function __construct(array $nodes, array $edges)
    {
        $nodes = array_map(fn ($n) => $n instanceof RouteNode ? $n : throw new ConfigurationException('route node must be RouteNode'), $nodes);
        $seqs  = array_map(fn (RouteNode $n) => $n->seq, array_values($nodes));
        if (count($seqs) !== count(array_unique($seqs))) {
            throw new ConfigurationException('mes_route_topo_duplicate_seq');
        }
        ksort($nodes);
        $this->nodes  = $nodes;
        $this->seqs   = array_map('intval', array_keys($nodes));
        $this->edges  = array_values($edges);
        $outF = $outR = $in = [];
        foreach ($this->edges as $e) {
            if (!isset($this->nodes[$e->fromSeq], $this->nodes[$e->toSeq]) && !isset($this->nodes[$e->fromSeq])) {
                // 悬空边不参与索引（引用完整性由宿主 P2-05 校验负责），但保留在 edges() 供 diff
                continue;
            }
            if ($e->type === TransitionType::Rework) {
                $outR[$e->fromSeq][] = $e;
            } else {
                $outF[$e->fromSeq][] = $e;
            }
            if (isset($this->nodes[$e->toSeq])) {
                $in[$e->toSeq][] = $e;
            }
        }
        $this->outForward = $outF;
        $this->outRework  = $outR;
        $this->inbound    = $in;
    }

    public function node(int $seq): ?RouteNode
    {
        return $this->nodes[$seq] ?? null;
    }

    /** @return array<int, RouteNode> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function nodeCount(): int
    {
        return count($this->nodes);
    }

    /** @return list<RouteEdge> */
    public function edges(): array
    {
        return $this->edges;
    }

    /** @return list<RouteEdge> type=1/2/3 出边 */
    public function forwardEdgesFrom(int $seq): array
    {
        return $this->outForward[$seq] ?? [];
    }

    /** @return list<RouteEdge> type=4 返工回边 */
    public function reworkEdgesFrom(int $seq): array
    {
        return $this->outRework[$seq] ?? [];
    }

    /** @return list<RouteEdge> */
    public function inboundEdges(int $seq): array
    {
        return $this->inbound[$seq] ?? [];
    }

    /** @return list<int> seq 升序 */
    public function sortedSeqs(): array
    {
        return $this->seqs;
    }

    public function firstSeq(): ?int
    {
        return $this->seqs[0] ?? null;
    }

    /** 隐式直线：seq 顺延的下一工序（U-BE-RG-002） */
    public function nextSeqInOrder(int $seq): ?int
    {
        $idx = array_search($seq, $this->seqs, true);
        return $idx !== false && isset($this->seqs[$idx + 1]) ? $this->seqs[$idx + 1] : null;
    }

    /** 尾工序：无正向出边且无隐式下一站（U-BE-RG-008） */
    public function isTail(int $seq): bool
    {
        return ($this->outForward[$seq] ?? []) === [] && $this->nextSeqInOrder($seq) === null;
    }

    /** @return list<int> */
    public function tailSeqs(): array
    {
        return array_values(array_filter($this->seqs, fn (int $s) => $this->isTail($s)));
    }

    /** 分叉入边计数：汇合就绪/branch_key 语义用（U-BE-RG-006） */
    public function inboundBranchCount(int $seq): int
    {
        return count(array_filter(
            $this->inbound[$seq] ?? [],
            fn (RouteEdge $e) => $e->type === TransitionType::Branch
        ));
    }
}

<?php
declare(strict_types=1);

namespace madong\stepcore\Tests;

use madong\stepcore\enum\TransitionType;
use madong\stepcore\exception\ConfigurationException;
use madong\stepcore\model\RouteGraph;
use madong\stepcore\parser\RouteGraphParser;
use PHPUnit\Framework\TestCase;

/**
 * RouteGraph + Parser 纯单测（P3-01 验收，04 §3.2）。
 */
final class RouteGraphParserTest extends TestCase
{
    /** U-BE-RG-002 直线链：forward 顺延 + tailSeqs */
    public function testStraightChain(): void
    {
        $graph = $this->graph('straight');
        $this->assertSame(4, $graph->nodeCount());
        $this->assertSame([40], $graph->tailSeqs());
        $this->assertSame(20, $graph->nextSeqInOrder(10));
        $this->assertSame([10, 20, 30, 40], $graph->sortedSeqs());
    }

    /** U-BE-RG-001 空路线：firstSeq=null，不抛 */
    public function testEmptyGraph(): void
    {
        $graph = RouteGraphParser::fromSnapshot(['processes' => [], 'transitions' => []]);
        $this->assertNull($graph->firstSeq());
        $this->assertSame([], $graph->tailSeqs());
    }

    /** U-BE-RG-003 显式 type=1：10→30，不产生隐式 10→20 */
    public function testExplicitStraightEdge(): void
    {
        $graph = $this->graph('explicit');
        $edges = $graph->forwardEdgesFrom(10);
        $this->assertCount(1, $edges);
        $this->assertSame(30, $edges[0]->toSeq);
        $this->assertSame(TransitionType::Straight, $edges[0]->type);
    }

    /** U-BE-RG-004 分叉 70/30：两条出边权重合计 100 */
    public function testBranchEdges(): void
    {
        $graph  = $this->graph('branch');
        $edges  = $graph->forwardEdgesFrom(10);
        $this->assertCount(2, $edges);
        $this->assertSame(100, array_sum(array_map(fn ($e) => (int) $e->weight, $edges)));
        $this->assertSame(1, $graph->inboundBranchCount(30));
    }

    /** U-BE-RG-005 权重全空：weight=null 均分由 resolver 处理 */
    public function testNullWeights(): void
    {
        $graph = $this->graph('branch', ['weight' => null]);
        $edges = $graph->forwardEdgesFrom(10);
        $this->assertNull($edges[0]->weight);
    }

    /** U-BE-RG-006 汇合入边：inbound[40] 含两条分支边 */
    public function testJoinInbound(): void
    {
        $graph = $this->graph('branch_join');
        $in    = $graph->inboundEdges(40);
        $this->assertCount(2, $in);
        $this->assertSame(50, $graph->nextSeqInOrder(40));
    }

    /** U-BE-RG-007 返工回边：落 rework 索引，不进 forward */
    public function testReworkEdgeSeparated(): void
    {
        $graph = $this->graph('straight', ['extra_transition' => ['from_seq' => 30, 'to_seq' => 10, 'type' => 4, 'condition' => ['auto_on_ng' => true]]]);
        $this->assertCount(0, $graph->forwardEdgesFrom(30));
        $this->assertCount(1, $graph->reworkEdgesFrom(30));
        $this->assertSame(40, $graph->nextSeqInOrder(30));
    }

    /** U-BE-RG-008 尾工序：isTail 判定 */
    public function testTailDetection(): void
    {
        $graph = $this->graph('straight');
        $this->assertTrue($graph->isTail(40));
        $this->assertFalse($graph->isTail(30));
    }

    /** U-BE-RG-010 重复 seq：编译抛配置异常，不静默覆盖 */
    public function testDuplicateSeqThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        RouteGraphParser::fromSnapshot([
            'processes'   => [
                ['seq' => 10, 'id' => 'p1', 'code' => 'A'],
                ['seq' => 10, 'id' => 'p2', 'code' => 'B'],
            ],
            'transitions' => [],
        ]);
    }

    /** U-BE-TR-013 未知条件键：解析期直接抛，不静默 */
    public function testUnknownConditionKeyThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('foo');
        RouteGraphParser::fromSnapshot([
            'processes'   => [['seq' => 10, 'id' => 'p1']],
            'transitions' => [['from_seq' => 10, 'to_seq' => 10, 'type' => 4, 'condition' => ['foo' => true]]],
        ]);
    }

    /** 条件 JSON 字符串归一化 + 枚举 type 兼容 */
    public function testConditionStringAndEnumType(): void
    {
        $graph = $this->graph('straight', [
            'extra_transition' => [
                'from_seq' => 30, 'to_seq' => 20, 'type' => TransitionType::Rework,
                'condition' => '{"auto_on_ng":true,"defect_codes":["B01"]}',
            ],
        ]);
        $edge = $graph->reworkEdgesFrom(30)[0];
        $this->assertSame(['auto_on_ng' => true, 'defect_codes' => ['B01']], $edge->condition);
    }

    /** @param array<string, mixed> $opts */
    private function graph(string $shape, array $opts = []): RouteGraph
    {
        return RouteGraphParser::fromSnapshot(TestRoutes::snapshot($shape, $opts));
    }
}

<?php
declare(strict_types=1);

namespace madong\stepcore\Tests;

use madong\stepcore\model\FlowContext;
use madong\stepcore\model\FlowEdge;
use madong\stepcore\model\FlowGraph;
use madong\stepcore\model\FlowNode;
use madong\stepcore\parser\ConditionEvaluator;
use madong\stepcore\route\FlowTransitionResolver;
use PHPUnit\Framework\TestCase;

final class FlowTransitionResolverTest extends TestCase
{
    public function testHighestPriorityWins(): void
    {
        $graph = $this->graph([
            new FlowEdge('ed_low', 'nd_a', 'nd_c', 'straight', ['priority' => 50, 'enabled' => true]),
            new FlowEdge('ed_high', 'nd_a', 'nd_b', 'straight', ['priority' => 100, 'enabled' => true]),
        ]);
        $result = $this->resolver()->resolve($graph, 'nd_a', $this->ctx($graph));
        self::assertSame('straight', $result->type);
        self::assertSame('nd_b', $result->toNodeKey);
        self::assertSame('ed_high', $result->edgeKey);
    }

    public function testConditionFiltersCandidateAndFallsBack(): void
    {
        $graph = $this->graph([
            new FlowEdge('ed_cond', 'nd_a', 'nd_b', 'straight', [
                'priority' => 200, 'enabled' => true,
                'condition' => ['expr' => '#qty_ng > 0', 'rules' => []],
            ]),
            new FlowEdge('ed_plain', 'nd_a', 'nd_c', 'straight', ['priority' => 100, 'enabled' => true]),
        ]);
        $ctx = $this->ctx($graph, ['qty_ng' => 0]);
        self::assertSame('nd_c', $this->resolver()->resolve($graph, 'nd_a', $ctx)->toNodeKey);
        $ctx2 = $this->ctx($graph, ['qty_ng' => 3]);
        self::assertSame('nd_b', $this->resolver()->resolve($graph, 'nd_a', $ctx2)->toNodeKey);
    }

    public function testTailByAbsenceOfOutgoingEdges(): void
    {
        $graph = $this->graph([]);
        $result = $this->resolver()->resolve($graph, 'nd_c', $this->ctx($graph));
        self::assertSame('tail', $result->type);
    }

    public function testNoMatchingTransitionIsBlockedNotSeqFallback(): void
    {
        $graph = $this->graph([
            new FlowEdge('ed_off', 'nd_a', 'nd_b', 'straight', ['priority' => 100, 'enabled' => false]),
        ]);
        $result = $this->resolver()->resolve($graph, 'nd_a', $this->ctx($graph));
        self::assertSame('blocked', $result->type);
        self::assertSame('mes_flow_no_transition', $result->errorCode);
    }

    public function testBranchSelectionIsDeterministicForSameSeed(): void
    {
        $graph = $this->graph([
            new FlowEdge('ed_70', 'nd_a', 'nd_b', 'branch', ['priority' => 100, 'enabled' => true, 'weight' => 70]),
            new FlowEdge('ed_30', 'nd_a', 'nd_c', 'branch', ['priority' => 100, 'enabled' => true, 'weight' => 30]),
        ]);
        $resolver = $this->resolver();
        $ctx = new FlowContext(action: 'pass', unitNo: 'SN-001', nodeKey: 'nd_a', graph: $graph);
        $first = $resolver->resolve($graph, 'nd_a', $ctx);
        $second = $resolver->resolve($graph, 'nd_a', $ctx);
        self::assertSame('branch', $first->type);
        self::assertSame($first->v2Targets(), $second->v2Targets());
        self::assertCount(1, $first->v2Targets());
    }

    private function resolver(): FlowTransitionResolver
    {
        return new FlowTransitionResolver(new ConditionEvaluator());
    }

    /** @param list<FlowEdge> $edges */
    private function graph(array $edges): FlowGraph
    {
        return new FlowGraph([
            new FlowNode('nd_a', 'step', 'A'),
            new FlowNode('nd_b', 'step', 'B'),
            new FlowNode('nd_c', 'end', 'C'),
        ], $edges);
    }

    /** @param array<string, mixed> $variable */
    private function ctx(FlowGraph $graph, array $variable = []): FlowContext
    {
        return new FlowContext(action: 'pass', nodeKey: 'nd_a', variable: $variable, graph: $graph);
    }
}

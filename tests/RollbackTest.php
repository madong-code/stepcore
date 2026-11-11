<?php
declare(strict_types=1);

namespace madong\stepcore\Tests;

use madong\stepcore\exception\ConfigurationException;
use madong\stepcore\model\FlowContext;
use madong\stepcore\model\FlowEdge;
use madong\stepcore\model\FlowGraph;
use madong\stepcore\model\FlowNode;
use madong\stepcore\rollback\RollbackContext;
use madong\stepcore\rollback\RollbackPolicy;
use madong\stepcore\route\RollbackResolver;
use PHPUnit\Framework\TestCase;

final class RollbackTest extends TestCase
{
    public function testStepBackUsesLatestSuccessfulTransition(): void
    {
        $graph = $this->graph();
        $ctx = new FlowContext(action: 'pass', nodeKey: 'nd_b', rollbackMode: 'step_back',
            reasonCode: 'R01', graph: $graph);
        $rb = RollbackContext::from($ctx, [
            ['result' => 4, 'mode' => 'rollback', 'from_node_key' => 'nd_x'],
            ['result' => 1, 'mode' => 'straight', 'from_node_key' => 'nd_a'],
        ]);
        self::assertSame('nd_a', (new RollbackResolver())->resolve($graph, $rb));
        self::assertSame('nd_a', (new RollbackPolicy('step_back'))->compensate($rb)->toNodeKey);
    }

    public function testJumpRequiresExistingTarget(): void
    {
        $graph = $this->graph();
        $ctx = new FlowContext(action: 'pass', nodeKey: 'nd_b', rollbackMode: 'jump',
            targetNodeKey: 'nd_c', reasonCode: 'R02', graph: $graph);
        self::assertSame('nd_c', (new RollbackResolver())->resolve($graph, RollbackContext::from($ctx)));

        $bad = new FlowContext(action: 'pass', nodeKey: 'nd_b', rollbackMode: 'jump',
            targetNodeKey: 'nd_missing', reasonCode: 'R02', graph: $graph);
        $this->expectException(ConfigurationException::class);
        (new RollbackResolver())->resolve($graph, RollbackContext::from($bad));
    }

    public function testReworkUsesPropertyTarget(): void
    {
        $graph = $this->graph();
        $ctx = new FlowContext(action: 'pass', nodeKey: 'nd_b', rollbackMode: 'rework',
            properties: ['rework_to_node_key' => 'nd_a'], graph: $graph);
        self::assertSame('nd_a', (new RollbackResolver())->resolve($graph, RollbackContext::from($ctx)));
    }

    public function testStaticModesDoNotMoveToken(): void
    {
        $graph = $this->graph();
        foreach (['reversal', 'replay', 'cancel'] as $mode) {
            $ctx = new FlowContext(action: 'pass', nodeKey: 'nd_b', rollbackMode: $mode, graph: $graph);
            self::assertSame('', (new RollbackResolver())->resolve($graph, RollbackContext::from($ctx)));
        }
    }

    public function testPolicyGuards(): void
    {
        $graph = $this->graph();
        $ctx = new FlowContext(action: 'pass', nodeKey: 'nd_b', rollbackMode: 'step_back', graph: $graph);
        $base = RollbackContext::from($ctx, [['result' => 1, 'mode' => 'straight', 'from_node_key' => 'nd_a']]);
        $policy = new RollbackPolicy('step_back');

        self::assertFalse($policy->can($base, ''), '原因码必填');
        self::assertTrue($policy->can($base, 'R01'));
        self::assertFalse($policy->can($base->with(['archived' => true]), 'R01'));
        self::assertFalse($policy->can($base->with(['sameDay' => false]), 'R01'));
        self::assertFalse($policy->can($base->with(['hasPermission' => false]), 'R01'));
        self::assertFalse($policy->can($base->with(['reentryCount' => 2, 'maxReworkTimes' => 2]), 'R01'));
    }

    public function testReplayAndCancelResults(): void
    {
        $graph = $this->graph();
        $replay = new FlowContext(action: 'pass', nodeKey: 'nd_b', rollbackMode: 'replay', graph: $graph);
        $cancel = new FlowContext(action: 'pass', nodeKey: 'nd_b', rollbackMode: 'cancel',
            reasonCode: 'R09', graph: $graph);
        self::assertTrue((new RollbackPolicy('replay'))->compensate(RollbackContext::from($replay))->replayed);
        $r = (new RollbackPolicy('cancel'))->compensate(RollbackContext::from($cancel));
        self::assertSame('cancel', $r->type);
        self::assertSame('R09', $r->reasonCode);
    }

    private function graph(): FlowGraph
    {
        return new FlowGraph([
            new FlowNode('nd_a', 'step', 'A'),
            new FlowNode('nd_b', 'step', 'B'),
            new FlowNode('nd_c', 'end', 'C'),
        ], [
            new FlowEdge('ed_1', 'nd_a', 'nd_b', 'straight', ['priority' => 100]),
            new FlowEdge('ed_2', 'nd_b', 'nd_c', 'straight', ['priority' => 100]),
        ]);
    }
}

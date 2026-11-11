<?php
declare(strict_types=1);

namespace madong\stepcore\Tests;

use madong\stepcore\exception\ConfigurationException;
use madong\stepcore\model\FlowNode;
use madong\stepcore\parser\FlowGraphParser;
use PHPUnit\Framework\TestCase;

final class FlowGraphParserTest extends TestCase
{
    public function testParseV2BuildsNodeKeyIndex(): void
    {
        $graph = (new FlowGraphParser())->parse($this->v2());
        self::assertSame(3, $graph->nodeCount());
        self::assertTrue($graph->has('nd_a'));
        self::assertSame('step', $graph->node('nd_a')?->type);
        self::assertSame('OP10', $graph->node('nd_a')?->property('code'));
        self::assertCount(1, $graph->outEdgesFrom('nd_s'));
        self::assertSame('nd_c', $graph->outEdgesFrom('nd_a')[0]->toNodeKey);
        self::assertTrue($graph->isTail('nd_c'));
        self::assertSame([], $graph->validate());
    }

    public function testV1MovesBusinessFieldsIntoProperties(): void
    {
        $graph = (new FlowGraphParser())->fromV1([
            'nodes' => [
                ['seq' => 10, 'key' => 'nd_a', 'name' => 'A', 'node_kind' => 1,
                 'scan_mode' => 2, 'qc_gate' => true, 'pre_hooks' => ['h1'],
                 'steps' => [['id' => 'st_1', 'content' => '确认', 'device_params' => ['t' => 1]]]],
                ['seq' => 20, 'key' => 'nd_b', 'name' => 'B', 'node_kind' => 1, 'rework_to_seq' => 10],
            ],
            'edges' => [],
        ]);

        $a = $graph->node('nd_a');
        self::assertInstanceOf(FlowNode::class, $a);
        self::assertSame(2, $a->property('scan_mode'));
        self::assertTrue($a->property('qc_gate'));
        self::assertSame(['h1'], $a->property('hooks')['pre']);
        self::assertSame('st_1', $a->steps[0]['step_key']);
        self::assertSame('确认', $a->steps[0]['properties']['content']);
        self::assertSame(['t' => 1], $a->steps[0]['properties']['device_params']);
        self::assertSame('nd_a', $graph->node('nd_b')?->property('rework_to_node_key'));
    }

    public function testV1MaterializesImplicitSeqOrder(): void
    {
        $graph = (new FlowGraphParser())->fromV1([
            'nodes' => [
                ['seq' => 10, 'key' => 'nd_a', 'name' => 'A'],
                ['seq' => 20, 'key' => 'nd_b', 'name' => 'B'],
                ['seq' => 30, 'key' => 'nd_c', 'name' => 'C'],
            ],
            'edges' => [],
        ]);
        self::assertCount(2, $graph->edges());
        self::assertSame('nd_b', $graph->outEdgesFrom('nd_a')[0]->toNodeKey);
        self::assertSame('straight', $graph->outEdgesFrom('nd_a')[0]->mode);
        self::assertSame([], $graph->outEdgesFrom('nd_c'));
        self::assertTrue($graph->isTail('nd_c'));
    }

    public function testV1MapsTransitionTypesToModes(): void
    {
        $graph = (new FlowGraphParser())->fromV1([
            'nodes' => [
                ['seq' => 10, 'key' => 'nd_a', 'name' => 'A'],
                ['seq' => 20, 'key' => 'nd_b', 'name' => 'B'],
                ['seq' => 30, 'key' => 'nd_c', 'name' => 'C'],
            ],
            'edges' => [
                ['from_seq' => 10, 'to_seq' => 20, 'type' => 2, 'weight' => 70],
                ['from_seq' => 10, 'to_seq' => 30, 'type' => 2, 'weight' => 30],
            ],
        ]);
        $edges = $graph->outEdgesFrom('nd_a');
        self::assertCount(2, $edges);
        self::assertSame('branch', $edges[0]->mode);
        self::assertSame(70, $edges[0]->weight());
    }

    public function testGraphHashStableAcrossLayoutChange(): void
    {
        $base = $this->v2();
        $base['layout']['viewport'] = ['zoom' => 1];
        $a = FlowGraphParser::graphHash($base);
        $base['layout']['viewport'] = ['zoom' => 2];
        $b = FlowGraphParser::graphHash($base);
        self::assertSame($a, $b);
        $base['nodes'][1]['properties']['scan_mode'] = 9;
        self::assertNotSame($a, FlowGraphParser::graphHash($base));
    }

    public function testDuplicateNodeKeyThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        (new FlowGraphParser())->fromV2([
            'nodes' => [
                ['node_key' => 'nd_a', 'type' => 'step', 'name' => 'A'],
                ['node_key' => 'nd_a', 'type' => 'step', 'name' => 'B'],
            ],
            'transitions' => [],
        ]);
    }

    /** @return array<string, mixed> */
    private function v2(): array
    {
        return [
            'schema_version' => 2,
            'meta' => ['code' => 'RT-001', 'version' => 'V1'],
            'nodes' => [
                ['node_key' => 'nd_s', 'type' => 'start', 'name' => '开始', 'properties' => []],
                ['node_key' => 'nd_a', 'type' => 'step', 'name' => 'A',
                 'properties' => ['code' => 'OP10', 'scan_mode' => 1]],
                ['node_key' => 'nd_c', 'type' => 'end', 'name' => '结束', 'properties' => []],
            ],
            'transitions' => [
                ['edge_key' => 'ed_1', 'from_node_key' => 'nd_s', 'to_node_key' => 'nd_a',
                 'mode' => 'straight', 'properties' => ['priority' => 100]],
                ['edge_key' => 'ed_2', 'from_node_key' => 'nd_a', 'to_node_key' => 'nd_c',
                 'mode' => 'straight', 'properties' => ['priority' => 100]],
            ],
            'layout' => ['nodes' => [], 'viewport' => [], 'groups' => []],
        ];
    }
}

<?php
declare(strict_types=1);

namespace madong\stepcore\Tests;

use madong\stepcore\exception\ConfigurationException;
use madong\stepcore\model\FlowResult;
use madong\stepcore\parser\RouteGraphParser;
use madong\stepcore\route\TransitionResolver;
use PHPUnit\Framework\TestCase;

/**
 * TransitionResolver 纯单测（P3-03 验收，04 §3.3）。
 */
final class TransitionResolverTest extends TestCase
{
    private TransitionResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new TransitionResolver();
    }

    /** U-BE-TR-001 隐式直线：from=10 → straight seq=20 */
    public function testImplicitStraight(): void
    {
        $r = $this->resolver->resolve($this->graph('straight'), 10);
        $this->assertSame('straight', $r->type);
        $this->assertSame(20, $r->seq);
        $legacy = $r->toLegacyArray();
        $this->assertSame(['type' => 'straight', 'seq' => 20, 'process' => $legacy['process']], $legacy);
    }

    /** U-BE-TR-002 显式直线（D4 修复）：from=10 → straight seq=30，不是 20 */
    public function testExplicitStraight(): void
    {
        $r = $this->resolver->resolve($this->graph('explicit'), 10);
        $this->assertSame('straight', $r->type);
        $this->assertSame(30, $r->seq);
    }

    /** U-BE-TR-003 尾工序：from=40 → tail */
    public function testTail(): void
    {
        $r = $this->resolver->resolve($this->graph('straight'), 40);
        $this->assertSame('tail', $r->type);
        $this->assertSame(['type' => 'tail'], $r->toLegacyArray());
    }

    /** U-BE-TR-004 分叉固定种子：同 seed 多次调用结果一致 */
    public function testPickBranchDeterministic(): void
    {
        $preview = $this->resolver->resolve($this->graph('branch'), 10);
        $a = $this->resolver->pickBranch($preview, 'SN-A1');
        $b = $this->resolver->pickBranch($preview, 'SN-A1');
        $this->assertSame($a->seq, $b->seq);
        $this->assertSame($a->branchKey, $b->branchKey);
        $this->assertContains($a->seq, [20, 30]);
    }

    /** U-BE-TR-005 分叉分布：1000 个 seed 约 70/30（容差 ±5%） */
    public function testPickBranchDistribution(): void
    {
        $preview = $this->resolver->resolve($this->graph('branch'), 10);
        $seq20   = 0;
        for ($i = 0; $i < 1000; $i++) {
            $picked = $this->resolver->pickBranch($preview, 'SN-' . $i);
            if ($picked->seq === 20) {
                $seq20++;
            }
        }
        $this->assertEqualsWithDelta(700, $seq20, 50);
    }

    /** U-BE-TR-006 权重全空：均分 50/50 */
    public function testEqualSplitWhenWeightsEmpty(): void
    {
        $preview = $this->resolver->resolve($this->graph('branch', ['weight' => null]), 10);
        $this->assertSame('branch', $preview->type);
        $this->assertSame(2, $preview->totalWeight);
        $seq20 = 0;
        for ($i = 0; $i < 1000; $i++) {
            $seq20 += $this->resolver->pickBranch($preview, 'SN-' . $i)->seq === 20 ? 1 : 0;
        }
        $this->assertEqualsWithDelta(500, $seq20, 50);
    }

    /** U-BE-TR-007 权重 0/0：非法配置报错（部分边显式配 0） */
    public function testZeroWeightThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->resolver->resolve($this->graph('branch', ['weight' => 0, 'w1' => 0, 'w2' => 0]), 10);
    }

    /** U-BE-TR-008 单分支出边：解析仍返回该支（branch_single 由校验器负责） */
    public function testSingleBranchStillResolves(): void
    {
        $g = RouteGraphParser::fromSnapshot([
            'processes'   => [['seq' => 10, 'id' => 'p10'], ['seq' => 20, 'id' => 'p20']],
            'transitions' => [['from_seq' => 10, 'to_seq' => 20, 'type' => 2, 'weight' => 100]],
        ]);
        $r = $this->resolver->resolve($g, 10);
        $this->assertSame('branch', $r->type);
        $this->assertSame(20, $this->resolver->pickBranch($r, 'SN-X')->seq);
    }

    /** U-BE-TR-009 汇合出边：from=40 → straight seq=50 */
    public function testJoinOut(): void
    {
        $r = $this->resolver->resolve($this->graph('branch_join'), 40);
        $this->assertSame('straight', $r->type);
        $this->assertSame(50, $r->seq);
    }

    /** U-BE-TR-010 仅返工出边：按 seq 顺延/tail，不返回首工序 */
    public function testOnlyReworkFallsToOrder(): void
    {
        $r = $this->resolver->resolve($this->graph('rework_only'), 30);
        $this->assertSame('tail', $r->type);
        $r2 = $this->resolver->resolve($this->graph('rework_only'), 10);
        $this->assertSame(20, $r2->seq);
    }

    /** U-BE-TR-011 返工 auto_on_ng：NG 动作命中 → to_seq=20 */
    public function testReworkAutoOnNg(): void
    {
        $to = $this->resolver->resolveRework($this->graph('rework_only'), 30, 'report_ng');
        $this->assertSame(20, $to);
    }

    /** U-BE-TR-012 返工 defect_codes：B01 命中 / B02 不命中 */
    public function testReworkDefectCodes(): void
    {
        $g = RouteGraphParser::fromSnapshot([
            'processes'   => [['seq' => 10, 'id' => 'p10'], ['seq' => 20, 'id' => 'p20'], ['seq' => 30, 'id' => 'p30']],
            'transitions' => [
                ['from_seq' => 30, 'to_seq' => 20, 'type' => 4, 'condition' => ['auto_on_ng' => true, 'defect_codes' => ['B01']]],
            ],
        ]);
        $this->assertSame(20, $this->resolver->resolveRework($g, 30, 'report_ng', 'B01'));
        $this->assertNull($this->resolver->resolveRework($g, 30, 'report_ng', 'B02'));
    }

    /** U-BE-TR-014 分支预览：返回全部候选，不写库（纯函数无副作用） */
    public function testBranchPreviewListsAll(): void
    {
        $preview = $this->resolver->resolve($this->graph('branch'), 10);
        $this->assertCount(2, $preview->branches);
        $this->assertSame([20, 30], array_map(fn ($b) => $b['seq'], $preview->branches));
        $this->assertSame(100, $preview->totalWeight);
    }

    /** toLegacyArray 与旧 resolveNext 输出形状一致（影子比对契约） */
    public function testLegacyShape(): void
    {
        $legacy = $this->resolver->resolve($this->graph('branch'), 10)->toLegacyArray();
        $this->assertSame(['type', 'branches', 'total_weight'], array_keys($legacy));
        $this->assertSame(['seq', 'weight', 'process'], array_keys($legacy['branches'][0]));
        $this->assertSame(['type' => 'tail'], (new FlowResult('tail'))->toLegacyArray());
    }

    private function graph(string $shape, array $opts = []): \madong\stepcore\model\RouteGraph
    {
        return RouteGraphParser::fromSnapshot(TestRoutes::snapshot($shape, $opts));
    }
}

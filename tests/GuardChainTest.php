<?php
declare(strict_types=1);

namespace madong\stepcore\Tests;

use madong\stepcore\exception\ConfigurationException;
use madong\stepcore\guard\GuardChain;
use madong\stepcore\guard\GuardInterface;
use madong\stepcore\guard\GuardOutcome;
use madong\stepcore\guard\GuardRegistry;
use madong\stepcore\model\FlowContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * GuardChain/Registry 纯单测（P3-04 验收，04 §3.4 GC-001~006）。
 */
final class GuardChainTest extends TestCase
{
    /** U-BE-GC-001 按 priority 升序执行 */
    public function testPriorityOrder(): void
    {
        $order = [];
        $chain = new GuardChain([
            $this->guard('late', 100, function () use (&$order) { $order[] = 'late'; return GuardOutcome::ok(); }),
            $this->guard('early', 10, function () use (&$order) { $order[] = 'early'; return GuardOutcome::ok(); }),
        ]);
        $report = $chain->run(new FlowContext('pass'));
        $this->assertTrue($report->passed());
        $this->assertSame(['early', 'late'], $order);
        $this->assertSame(['early', 'late'], array_map(fn ($e) => $e['name'], $report->executed));
    }

    /** U-BE-GC-002 阻断短路：首个 block 后不执行后续，返回全部已执行明细 */
    public function testShortCircuitOnBlock(): void
    {
        $reached = [];
        $chain = new GuardChain([
            $this->guard('first', 10, fn () => GuardOutcome::ok()),
            $this->guard('blocker', 20, function () use (&$reached) {
                $reached[] = 'blocker';
                return GuardOutcome::block('mes_wo_paused_blocked', '工单已暂停');
            }),
            $this->guard('never', 30, function () use (&$reached) {
                $reached[] = 'never';
                return GuardOutcome::ok();
            }),
        ]);
        $report = $chain->run(new FlowContext('pass'));
        $this->assertFalse($report->passed());
        $this->assertSame(['blocker'], $reached);
        $this->assertSame('mes_wo_paused_blocked', $report->firstBlock()?->errorCode);
        $this->assertCount(2, $report->executed);
        $this->assertTrue($report->shortCircuited);
    }

    /** U-BE-GC-003 警告聚合：warn 不阻断，聚合到 warnings */
    public function testWarningsAggregated(): void
    {
        $chain = new GuardChain([
            $this->guard('warn1', 10, fn () => GuardOutcome::warn('mes_flow_low_yield', '良率偏低')),
            $this->guard('warn2', 20, fn () => GuardOutcome::warn('mes_flow_low_yield2')),
            $this->guard('ok', 30, fn () => GuardOutcome::ok()),
        ]);
        $report = $chain->run(new FlowContext('pass'));
        $this->assertTrue($report->passed());
        $this->assertCount(2, $report->warnings);
    }

    /** U-BE-GC-004 collectAll：一次性返回所有 block 明细（不短路不吞） */
    public function testCollectAllBlocks(): void
    {
        $chain = new GuardChain([
            $this->guard('b1', 10, fn () => GuardOutcome::block('mes_unit_blocked')),
            $this->guard('b2', 20, fn () => GuardOutcome::block('mes_transfer_no_qty')),
        ]);
        $report = $chain->run(new FlowContext('pass'), collectAll: true);
        $this->assertCount(2, $report->blocks);
        $this->assertSame(['mes_unit_blocked', 'mes_transfer_no_qty'], array_map(fn ($b) => $b->errorCode, $report->blocks));
    }

    /** U-BE-GC-005 守卫抛异常：转为 error 结果，不吞 */
    public function testExceptionConvertedToError(): void
    {
        $chain = new GuardChain([
            $this->guard('boom', 10, fn () => throw new RuntimeException('db lost')),
        ]);
        $report = $chain->run(new FlowContext('pass'));
        $this->assertFalse($report->passed());
        $this->assertSame('mes_flow_guard_error', $report->firstBlock()?->errorCode);
        $this->assertStringContainsString('db lost', (string) $report->firstBlock()?->detail);
    }

    /** U-BE-GC-006 未注册守卫：assertRegistered fail fast */
    public function testRegistryFailsFast(): void
    {
        $registry = new GuardRegistry();
        $registry->register($this->guard('exists', 10, fn () => GuardOutcome::ok()));
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('missing_guard');
        $registry->assertRegistered(['exists', 'missing_guard']);
    }

    public function testRegistryChain(): void
    {
        $registry = new GuardRegistry();
        $registry->register($this->guard('a', 20, fn () => GuardOutcome::ok()));
        $registry->register($this->guard('b', 10, fn () => GuardOutcome::ok()));
        $report = $registry->chain(['a', 'b'])->run(new FlowContext('pass'));
        $this->assertSame(['b', 'a'], array_map(fn ($e) => $e['name'], $report->executed));
    }

    private function guard(string $name, int $priority, \Closure $check): GuardInterface
    {
        return new class($name, $priority, $check) implements GuardInterface {
            public function __construct(
                private readonly string $n,
                private readonly int $p,
                private readonly \Closure $c,
            ) {
            }

            public function name(): string
            {
                return $this->n;
            }

            public function priority(): int
            {
                return $this->p;
            }

            public function check(FlowContext $ctx): GuardOutcome
            {
                return ($this->c)($ctx);
            }
        };
    }
}

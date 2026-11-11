<?php
declare(strict_types=1);

namespace madong\stepcore\Tests;

use madong\stepcore\action\ActionInterface;
use madong\stepcore\action\ActionRegistry;
use madong\stepcore\enum\TransitionType;
use madong\stepcore\event\EventBus;
use madong\stepcore\exception\ConfigurationException;
use madong\stepcore\Engine;
use madong\stepcore\exception\StateException;
use madong\stepcore\guard\GuardInterface;
use madong\stepcore\guard\GuardOutcome;
use madong\stepcore\guard\GuardRegistry;
use madong\stepcore\interface\services\IEventPublisher;
use madong\stepcore\model\FlowContext;
use madong\stepcore\model\FlowResult;
use madong\stepcore\state\StateMachine;
use PHPUnit\Framework\TestCase;

/**
 * StateMachine / Engine / EventBus 纯单测（P3-07/P3-12 包级验收，04 §3.5 SM-001~010）。
 */
final class StateMachineTest extends TestCase
{
    private StateMachine $unit;

    protected function setUp(): void
    {
        // 宿主 MesStateMachine 的 Unit 子表（数值状态，见 plugin/mes enum UnitStatus）
        $this->unit = new StateMachine([
            1 => [2, 4],        // created → wip / scrapped
            2 => [2, 3, 4, 5],  // wip → wip/reworking/scrapped/outsourced
            3 => [2, 4],        // reworking → wip/scrapped
            5 => [2],           // outsourced → wip
            4 => [4],           // scrapped 终态
            6 => [6],           // completed 终态
        ]);
    }

    /** U-BE-SM-001 Unit Created→Wip 允许 */
    public function testCreatedToWip(): void
    {
        $this->unit->ensure(1, 2, 'pass');
        $this->assertTrue($this->unit->can(1, 2));
    }

    /** U-BE-SM-002 Unit Completed→Wip 拒绝 */
    public function testCompletedToWipRejected(): void
    {
        try {
            $this->unit->ensure(6, 2, 'pass');
            $this->fail('expected StateException');
        } catch (StateException $e) {
            $this->assertStringContainsString('mes_flow_state_invalid', $e->getMessage());
            $this->assertSame('6', $e->from);
            $this->assertSame('2', $e->to);
            $this->assertSame('pass', $e->event);
        }
    }

    /** U-BE-SM-003 Wip→Reworking 允许；U-BE-SM-004 Wip→Outsourced→Wip 允许 */
    public function testWipTransitions(): void
    {
        $this->unit->ensure(2, 3, 'rework');
        $this->unit->ensure(2, 5, 'outsource');
        $this->unit->ensure(5, 2, 'outsource_back');
        $this->assertTrue($this->unit->can(2, 3) && $this->unit->can(2, 5) && $this->unit->can(5, 2));
    }

    /** U-BE-SM-010 非法迁移审计信息含 from/to/event */
    public function testExceptionCarriesContext(): void
    {
        $e = null;
        try {
            $this->unit->ensure(4, 2, 'revive');
        } catch (StateException $caught) {
            $e = $caught;
        }
        $this->assertNotNull($e);
        $this->assertSame(['4', '2', 'revive'], [$e->from, $e->to, $e->event]);
    }

    /** Engine：阻断路径 → FlowResult::blocked + 警告透出 */
    public function testEngineBlocked(): void
    {
        $engine = $this->engine([
            'wo' => fn () => GuardOutcome::block('mes_wo_paused_blocked', '暂停'),
            'qty' => fn () => GuardOutcome::block('mes_transfer_no_qty'),
            'warn' => fn () => GuardOutcome::warn('mes_flow_low_yield'),
        ]);
        $r = $engine->flow(new FlowContext('transfer'));
        $this->assertSame('blocked', $r->type);
        $this->assertSame('mes_wo_paused_blocked', $r->errorCode);
        $this->assertCount(1, $r->warnings);
    }

    /** Engine：放行 → 动作执行，warnings 合并；未注册动作 fail fast */
    public function testEngineExecutesAction(): void
    {
        $engine = $this->engine([
            'warn' => fn () => GuardOutcome::warn('mes_flow_low_yield'),
        ], new class implements ActionInterface {
            public function name(): string
            {
                return 'pass';
            }

            public function handle(FlowContext $ctx): FlowResult
            {
                return FlowResult::straight(20, ['code' => 'P2']);
            }
        });
        $r = $engine->flow(new FlowContext('pass'));
        $this->assertSame('straight', $r->type);
        $this->assertSame(20, $r->seq);
        $this->assertSame('mes_flow_low_yield', $r->warnings[0]['errorCode'] ?? null);

        $this->expectException(ConfigurationException::class);
        (clone $engine)->flow(new FlowContext('nope'));
    }

    /** EventBus：publish 仅入队，flush 才经 publisher 发出（事件不早于提交） */
    public function testEventBusDeferred(): void
    {
        $published = [];
        $publisher = new class($published) implements IEventPublisher {
            public function __construct(private array &$sink)
            {
            }

            public function publish(array $events): void
            {
                foreach ($events as $e) {
                    $this->sink[] = $e;
                }
            }
        };
        $bus = new EventBus($publisher);
        $bus->publish(['type' => 'mes.pass']);
        $this->assertSame([], $published);
        $this->assertCount(1, $bus->pending());
        $this->assertSame(1, $bus->flush());
        $this->assertSame([['type' => 'mes.pass']], $published);
        $this->assertSame(0, $bus->flush());
    }

    /** TransitionType 枚举与宿主 int 兼容 */
    public function testTransitionTypeFromMixed(): void
    {
        $this->assertSame(TransitionType::Rework, TransitionType::fromMixed(4));
        $this->assertSame(TransitionType::Branch, TransitionType::fromMixed(TransitionType::Branch));
    }

    /** @param array<string, \Closure> $guards */
    private function engine(array $guards, ?ActionInterface $passAction = null): Engine
    {
        $registry = new GuardRegistry();
        foreach ($guards as $name => $fn) {
            $registry->register(new class($name, $fn) implements GuardInterface {
                public function __construct(
                    private readonly string $n,
                    private readonly \Closure $f,
                ) {
                }

                public function name(): string
                {
                    return $this->n;
                }

                public function priority(): int
                {
                    // warn 守卫排最前，保证短路场景下 warning 已产生
                    return $this->n === 'warn' ? 5 : 10;
                }

                public function check(FlowContext $ctx): GuardOutcome
                {
                    return ($this->f)($ctx);
                }
            });
        }
        $actions = new ActionRegistry();
        $actions->register($passAction ?? new class implements ActionInterface {
            public function name(): string
            {
                return 'pass';
            }

            public function handle(FlowContext $ctx): FlowResult
            {
                return FlowResult::straight(20);
            }
        });
        return new Engine($registry, $actions, new EventBus(), [
            'pass'     => array_keys($guards),
            'transfer' => array_keys($guards),
        ]);
    }
}

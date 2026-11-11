<?php
declare(strict_types=1);

namespace madong\stepcore\guard;

use madong\stepcore\model\FlowContext;
use Throwable;

/**
 * 守卫链（P3-04）：
 * - 按 priority 升序执行（GC-001）；
 * - 首个 block 短路，不执行后续守卫（GC-002）；
 * - collectAll=true 时不短路，聚合全部 block 明细（GC-004，设计器/审计场景）；
 * - warn 聚合不阻断（GC-003）；
 * - 守卫抛异常转为 error 结果，不吞（GC-005）。
 */
final class GuardChain
{
    /** @param iterable<GuardInterface> $guards */
    public function __construct(private readonly iterable $guards)
    {
    }

    public function run(FlowContext $ctx, bool $collectAll = false): GuardReport
    {
        $guards = is_array($this->guards) ? $this->guards : iterator_to_array($this->guards);
        usort($guards, fn (GuardInterface $a, GuardInterface $b) => $a->priority() <=> $b->priority());

        $executed = [];
        $blocks   = [];
        $warnings = [];
        $short    = false;
        foreach ($guards as $guard) {
            try {
                $outcome = $guard->check($ctx);
            } catch (Throwable $e) {
                $outcome = GuardOutcome::error('mes_flow_guard_error', get_class($e) . ': ' . $e->getMessage());
            }
            $executed[] = ['name' => $guard->name(), 'outcome' => $outcome];
            if ($outcome->status === 'warn') {
                $warnings[] = $outcome;
            }
            if ($outcome->blocked()) {
                $blocks[] = $outcome;
                if (!$collectAll) {
                    $short = true;
                    break;
                }
            }
        }
        return new GuardReport($executed, $blocks, $warnings, $short);
    }
}

<?php
declare(strict_types=1);

namespace madong\stepcore;

use madong\stepcore\action\ActionInterface;
use madong\stepcore\action\ActionRegistry;
use madong\stepcore\contracts\RollbackPolicyInterface;
use madong\stepcore\event\EventBus;
use madong\stepcore\event\FlowEvent;
use madong\stepcore\guard\GuardReport;
use madong\stepcore\guard\GuardRegistry;
use madong\stepcore\model\FlowContext;
use madong\stepcore\model\FlowResult;
use madong\stepcore\rollback\RollbackContext;

/**
 * StepCore 统一编排入口（P3-12；02-tech-selection.md ADR-03）。
 *
 * flow(ctx)：
 *  1. 按 action 取守卫名单，跑守卫链（短路）；
 *  2. 阻断 → FlowResult::blocked（附警告明细）；
 *  3. 放行 → 注册的动作处理器执行；
 *  4. 动作产生的 warnings 合并进结果；
 *  5. 事件留在 EventBus，宿主事务提交后 flush()。
 */
final class Engine
{
    /** @param array<string, list<string>> $guardNames action => 守卫名单 */
    public function __construct(
        private readonly GuardRegistry $guards,
        private readonly ActionRegistry $actions,
        private readonly EventBus $events,
        private readonly array $guardNames = [],
    ) {
    }

    public function events(): EventBus
    {
        return $this->events;
    }

    public function flow(FlowContext $ctx): FlowResult
    {
        $names = $this->guardNames[$ctx->action] ?? [];
        if ($names !== []) {
            $report = $this->guards->chain($names)->run($ctx);
            if (!$report->passed()) {
                $block = $report->firstBlock();
                return new FlowResult(
                    'blocked',
                    errorCode: $block?->errorCode ?? 'mes_flow_guard_blocked',
                    errorDetail: $block?->detail,
                    warnings: $this->flattenWarnings($report),
                );
            }
            $warnings = $this->flattenWarnings($report);
        } else {
            $warnings = [];
        }

        $action = $this->actions->get($ctx->action);
        $result = $action->handle($ctx);
        if ($warnings !== []) {
            $vars           = get_object_vars($result);
            $vars['warnings'] = array_merge($vars['warnings'], $warnings);
            $result         = new FlowResult(...$vars);
        }
        return $result;
    }

    /**
     * v2 回退统一入口：策略守卫 → 补偿（宿主在单事务内落库）→ 事件入队（提交后 flush）。
     * jump / replay 共用同一链路，由 RollbackPolicyInterface 的 mode 区分。
     */
    public function rollback(RollbackContext $ctx, RollbackPolicyInterface $policy): FlowResult
    {
        if (!$policy->can($ctx, $ctx->reasonCode)) {
            return FlowResult::blocked('mes_flow_rollback_denied');
        }
        $this->events->publish(new FlowEvent(
            FlowEvent::ROLLBACK_BEFORE,
            $ctx->origin->instanceId,
            $ctx->fromNodeKey,
            ['mode' => $ctx->mode, 'reason_code' => $ctx->reasonCode],
        ));
        $result = $policy->compensate($ctx);
        $this->events->publish(new FlowEvent(
            FlowEvent::ROLLBACK_AFTER,
            $ctx->origin->instanceId,
            $ctx->fromNodeKey,
            ['mode' => $ctx->mode, 'target' => $result->toNodeKey],
        ));
        return $result;
    }

    public function jump(RollbackContext $ctx, RollbackPolicyInterface $policy): FlowResult
    {
        return $this->rollback($ctx, $policy);
    }

    public function replay(RollbackContext $ctx, RollbackPolicyInterface $policy): FlowResult
    {
        return $this->rollback($ctx, $policy);
    }

    /** @return list<array{status:string,errorCode:?string,detail:?string}> */
    private function flattenWarnings(GuardReport $report): array
    {
        return array_map(fn ($w) => [
            'status'     => $w->status,
            'errorCode'  => $w->errorCode,
            'detail'     => $w->detail,
        ], $report->warnings);
    }
}

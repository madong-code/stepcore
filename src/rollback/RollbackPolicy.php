<?php
declare(strict_types=1);

namespace madong\stepcore\rollback;

use madong\stepcore\contracts\RollbackPolicyInterface;
use madong\stepcore\model\FlowGraph;
use madong\stepcore\model\FlowResult;
use madong\stepcore\route\RollbackResolver;

/**
 * 默认回退策略（每种模式实例化一份注册到 ExtensionRegistry）。
 * 插件可实现 RollbackPolicyInterface 替换任意模式，引擎零改动。
 */
final class RollbackPolicy implements RollbackPolicyInterface
{
    private const REASON_REQUIRED = ['step_back', 'jump', 'cancel'];
    private const GUARDED_MODES   = ['step_back', 'jump', 'rework'];

    public function __construct(
        private readonly string $mode,
        private readonly ?RollbackResolver $resolver = null,
    ) {
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function can(RollbackContext $ctx, string $reason): bool
    {
        if ($ctx->mode !== $this->mode || $ctx->archived) {
            return false;
        }
        if (in_array($this->mode, self::REASON_REQUIRED, true) && trim($reason) === '') {
            return false;
        }
        if (!$ctx->hasPermission) {
            return false;
        }
        if (in_array($this->mode, self::GUARDED_MODES, true)) {
            if (!$ctx->sameDay) {
                return false;
            }
            if ($ctx->maxReworkTimes > 0 && $ctx->reentryCount >= $ctx->maxReworkTimes) {
                return false;
            }
        }
        return true;
    }

    public function resolveTarget(RollbackContext $ctx): string
    {
        $graph = $ctx->origin->graph;
        if (!$graph instanceof FlowGraph) {
            return '';
        }
        return ($this->resolver ?? new RollbackResolver())->resolve($graph, $ctx);
    }

    public function compensate(RollbackContext $ctx): FlowResult
    {
        return match ($this->mode) {
            'replay'   => FlowResult::replay(new FlowResult('straight', toNodeKey: $ctx->fromNodeKey)),
            'cancel'   => FlowResult::canceled($ctx->reasonCode),
            'reversal' => FlowResult::rollback($ctx->fromNodeKey, 'reversal'),
            default    => FlowResult::rollback($this->resolveTarget($ctx), $this->mode),
        };
    }
}

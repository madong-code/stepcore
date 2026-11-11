<?php
declare(strict_types=1);

namespace madong\stepcore\rollback;

use madong\stepcore\model\FlowContext;

/** 回退上下文：由宿主从 FlowContext 组装，供 RollbackPolicy / RollbackResolver 使用。 */
final class RollbackContext
{
    public function __construct(
        public readonly FlowContext $origin,
        public readonly string $mode,
        public readonly string $reasonCode = '',
        public readonly string $targetNodeKey = '',
        public readonly string $fromNodeKey = '',
        public readonly int $reentryCount = 0,
        public readonly int $maxReworkTimes = 0,
        public readonly bool $sameDay = true,
        public readonly bool $hasPermission = true,
        public readonly bool $archived = false,
        /** @var list<array<string, mixed>> 该实例最近的变迁日志（新→旧） */
        public readonly array $history = [],
    ) {
    }

    public static function from(FlowContext $ctx, array $history = []): self
    {
        return new self(
            origin: $ctx,
            mode: $ctx->rollbackMode !== '' ? $ctx->rollbackMode : 'step_back',
            reasonCode: $ctx->reasonCode,
            targetNodeKey: $ctx->targetNodeKey,
            fromNodeKey: $ctx->nodeKey,
            history: $history,
        );
    }

    public function with(array $overrides): self
    {
        $vars = get_object_vars($this);
        foreach ($overrides as $k => $v) {
            if (!array_key_exists($k, $vars)) {
                throw new \InvalidArgumentException('unknown RollbackContext field: ' . $k);
            }
            $vars[$k] = $v;
        }
        return new self(...$vars);
    }
}

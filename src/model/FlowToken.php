<?php
declare(strict_types=1);

namespace madong\stepcore\model;

/** 位置令牌：物化「当前在哪」，支持分叉/汇合/返工重入，替代 seq 单点推进。 */
final class FlowToken
{
    public const ACTIVE = 1;
    public const WAITING_JOIN = 2;
    public const CONSUMED = 3;

    public function __construct(
        public readonly string $id,
        public readonly string $instanceId,
        public readonly string $nodeKey,
        public readonly int $state = self::ACTIVE,
        public readonly ?string $edgeKey = null,
        public readonly ?string $branchKey = null,
        public readonly int $reentryCount = 0,
        public readonly string $createdAt = '',
    ) {
    }

    public function isActive(): bool
    {
        return $this->state === self::ACTIVE || $this->state === self::WAITING_JOIN;
    }

    public function consume(): self
    {
        return $this->with(['state' => self::CONSUMED]);
    }

    /** 变迁/回退生成的新令牌 */
    public function reborn(string $newId, string $toNodeKey, ?string $edgeKey, ?string $branchKey = null): self
    {
        return new self(
            $newId,
            $this->instanceId,
            $toNodeKey,
            self::ACTIVE,
            $edgeKey,
            $branchKey ?? $this->branchKey,
            $this->reentryCount + 1,
            $this->createdAt,
        );
    }

    /** @param array<string, mixed> $overrides */
    public function with(array $overrides): self
    {
        $vars = get_object_vars($this);
        foreach ($overrides as $k => $v) {
            if (!array_key_exists($k, $vars)) {
                throw new \InvalidArgumentException('unknown FlowToken field: ' . $k);
            }
            $vars[$k] = $v;
        }
        return new self(...$vars);
    }
}

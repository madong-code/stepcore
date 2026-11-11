<?php
declare(strict_types=1);

namespace madong\stepcore\model;

/** 节点任务：运行期仅保留未完成/挂起，完成即由 FlowArchiveService 归档出运行表。 */
final class FlowTask
{
    public const PENDING = 1;
    public const RUNNING = 2;
    public const FINISHED = 3;
    public const CANCELED = 4;
    public const SUSPENDED = 5;

    public function __construct(
        public readonly string $id,
        public readonly string $instanceId,
        public readonly string $nodeKey,
        public readonly int $state = self::PENDING,
        public readonly ?string $stepKey = null,
        public readonly ?string $woProcessId = null,
        public readonly ?string $operator = null,
        /** @var array<string, mixed> */
        public readonly array $variable = [],
        /** @var array<string, mixed> */
        public readonly array $properties = [],
        public readonly string $createdAt = '',
        public readonly ?string $finishedAt = null,
    ) {
    }

    public function isOpen(): bool
    {
        return in_array($this->state, [self::PENDING, self::RUNNING, self::SUSPENDED], true);
    }

    public function finish(?string $at = null): self
    {
        return $this->with(['state' => self::FINISHED, 'finishedAt' => $at]);
    }

    public function reopen(): self
    {
        return $this->with(['state' => self::RUNNING, 'finishedAt' => null]);
    }

    /** @param array<string, mixed> $overrides */
    public function with(array $overrides): self
    {
        $vars = get_object_vars($this);
        foreach ($overrides as $k => $v) {
            if (!array_key_exists($k, $vars)) {
                throw new \InvalidArgumentException('unknown FlowTask field: ' . $k);
            }
            $vars[$k] = $v;
        }
        return new self(...$vars);
    }
}

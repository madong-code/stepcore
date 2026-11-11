<?php
declare(strict_types=1);

namespace madong\stepcore\state;

use madong\stepcore\exception\StateException;

/**
 * 显式状态机（P3-07，通用）：from => [允许的 to...]。
 * 非法迁移抛 StateException（错误信息含 from/to/event，U-BE-SM-010）。
 */
final class StateMachine
{
    /** @var array<string, array<string, true>> */
    private array $transitions;

    /**
     * @param array<string|int, list<string|int>> $transitions from => [to...]
     */
    public function __construct(array $transitions)
    {
        $map = [];
        foreach ($transitions as $from => $tos) {
            $map[(string) $from] = array_fill_keys(array_map('strval', $tos), true);
        }
        $this->transitions = $map;
    }

    public function can(string|int|null $from, string|int $to): bool
    {
        return isset($this->transitions[(string) $from][(string) $to]);
    }

    /**
     * @throws StateException
     */
    public function ensure(string|int|null $from, string|int $to, string $event = ''): void
    {
        if (!$this->can($from, $to)) {
            throw new StateException((string) ($from ?? ''), (string) $to, $event);
        }
    }
}

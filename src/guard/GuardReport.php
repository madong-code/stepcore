<?php
declare(strict_types=1);

namespace madong\stepcore\guard;

/**
 * 守卫链执行报告（U-BE-GC-001~005）：
 * - passed：无 block/error；
 * - blocks/warnings/executed 全量明细，不吞任何结果。
 */
final class GuardReport
{
    /**
     * @param list<array{name:string,outcome:GuardOutcome}> $executed
     * @param list<GuardOutcome>                            $blocks
     * @param list<GuardOutcome>                            $warnings
     */
    public function __construct(
        public readonly array $executed = [],
        public readonly array $blocks = [],
        public readonly array $warnings = [],
        public readonly bool $shortCircuited = false,
    ) {
    }

    public function passed(): bool
    {
        return $this->blocks === [];
    }

    public function firstBlock(): ?GuardOutcome
    {
        return $this->blocks[0] ?? null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'passed'           => $this->passed(),
            'short_circuited'  => $this->shortCircuited,
            'executed'         => array_map(fn ($e) => $e['outcome']->toArray($e['name']), $this->executed),
            'blocks'           => array_map(fn (GuardOutcome $o) => $o->toArray(), $this->blocks),
            'warnings'         => array_map(fn (GuardOutcome $o) => $o->toArray(), $this->warnings),
        ];
    }
}

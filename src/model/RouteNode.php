<?php
declare(strict_types=1);

namespace madong\stepcore\model;

/**
 * 工序节点（值对象，seq 定位）。
 */
final class RouteNode
{
    /**
     * @param array<string, mixed> $attrs 工序属性（code/name/scan_mode/allow_rework/...）
     */
    public function __construct(
        public readonly int $seq,
        public readonly string $key,
        public readonly string $processId,
        public readonly array $attrs = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['seq' => $this->seq, 'key' => $this->key, 'process_id' => $this->processId] + $this->attrs;
    }
}

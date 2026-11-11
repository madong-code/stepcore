<?php
declare(strict_types=1);

namespace madong\stepcore\model;

use madong\stepcore\enum\TransitionType;

/**
 * 转移边（值对象）。type 1 直线 / 2 分叉 / 3 汇合 / 4 返工回边。
 */
final class RouteEdge
{
    /**
     * @param array<string, mixed> $condition 白名单 DSL（auto_on_ng/defect_codes/qty_below/yield_below/branch_by）
     */
    public function __construct(
        public readonly string $key,
        public readonly int $fromSeq,
        public readonly int $toSeq,
        public readonly TransitionType $type,
        public readonly ?int $weight = null,
        public readonly array $condition = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key'       => $this->key,
            'from_seq'  => $this->fromSeq,
            'to_seq'    => $this->toSeq,
            'type'      => $this->type->value,
            'weight'    => $this->weight,
            'condition' => $this->condition === [] ? null : $this->condition,
        ];
    }
}

<?php
declare(strict_types=1);

namespace madong\stepcore\model;

/** v2 变迁值对象：端点用 node_key，业务参数统一放 properties。 */
final class FlowEdge
{
    public const MODE_STRAIGHT = 'straight';
    public const MODE_BRANCH = 'branch';
    public const MODE_JOIN = 'join';
    public const MODE_REWORK = 'rework';
    public const MODE_CONDITIONAL = 'conditional';
    public const MODE_EVENT = 'event';
    public const MODE_TIMER = 'timer';
    public const MODE_ROLLBACK = 'rollback';
    public const MODE_JUMP = 'jump';
    public const MODE_CANCEL = 'cancel';

    /** @var list<string> 不参与正常前向解析的补偿类模式 */
    private const COMPENSATION_MODES = [self::MODE_ROLLBACK, self::MODE_JUMP, self::MODE_CANCEL];

    /** @param array<string, mixed> $properties */
    public function __construct(
        public readonly string $edgeKey,
        public readonly string $fromNodeKey,
        public readonly string $toNodeKey,
        public readonly string $mode = self::MODE_STRAIGHT,
        public readonly array $properties = [],
        public readonly array $ext = [],
    ) {
        if ($edgeKey === '' || $fromNodeKey === '' || $toNodeKey === '') {
            throw new \InvalidArgumentException('mes_flow_edge_key_required');
        }
    }

    public function property(string $key, mixed $default = null): mixed
    {
        return $this->properties[$key] ?? $default;
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->properties['enabled'] ?? true);
    }

    public function priority(): int
    {
        return (int) ($this->properties['priority'] ?? 100);
    }

    public function triggerType(): string
    {
        $trigger = $this->properties['trigger'] ?? [];
        return is_array($trigger) ? (string) ($trigger['type'] ?? 'manual') : 'manual';
    }

    /** @return array{expr:?string,rules:list<array<string,mixed>>} */
    public function condition(): array
    {
        $c = $this->properties['condition'] ?? [];
        return [
            'expr'  => is_array($c) ? ($c['expr'] ?? null) : null,
            'rules' => is_array($c) ? (array) ($c['rules'] ?? []) : [],
        ];
    }

    public function weight(): ?int
    {
        $w = $this->properties['weight'] ?? null;
        return $w === null ? null : (int) $w;
    }

    public function isForward(): bool
    {
        return $this->isEnabled() && !in_array($this->mode, self::COMPENSATION_MODES, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'edge_key'      => $this->edgeKey,
            'from_node_key' => $this->fromNodeKey,
            'to_node_key'   => $this->toNodeKey,
            'mode'          => $this->mode,
            'properties'    => $this->properties,
            'ext'           => $this->ext,
        ];
    }
}

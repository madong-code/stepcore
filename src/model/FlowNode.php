<?php
declare(strict_types=1);

namespace madong\stepcore\model;

/** v2 节点值对象：node_key 稳定标识，业务参数统一放 properties（对齐 workflow）。 */
final class FlowNode
{
    /** @var list<string> 不产生节点任务的纯路由类型 */
    private const ROUTING_TYPES = ['start', 'end', 'decision', 'split', 'join'];

    /**
     * @param array<string, mixed>  $properties 业务参数（按 type 的 propertySchema 校验）
     * @param list<array{step_key:string,properties:array<string,mixed>}> $steps
     * @param array<string, mixed>  $ext 插件扩展命名空间
     */
    public function __construct(
        public readonly string $nodeKey,
        public readonly string $type,
        public readonly string $name,
        public readonly array $properties = [],
        public readonly array $steps = [],
        public readonly array $ext = [],
    ) {
        if ($nodeKey === '') {
            throw new \InvalidArgumentException('mes_flow_node_key_required');
        }
    }

    public function property(string $key, mixed $default = null): mixed
    {
        return $this->properties[$key] ?? $default;
    }

    public function isExecutable(): bool
    {
        return !in_array($this->type, self::ROUTING_TYPES, true);
    }

    public function isEnd(): bool
    {
        return $this->type === 'end';
    }

    /** @return list<string> */
    public function stepKeys(): array
    {
        return array_values(array_map(fn (array $s) => (string) ($s['step_key'] ?? ''), $this->steps));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'node_key'   => $this->nodeKey,
            'type'       => $this->type,
            'name'       => $this->name,
            'properties' => $this->properties,
            'steps'      => $this->steps,
            'ext'        => $this->ext,
        ];
    }
}

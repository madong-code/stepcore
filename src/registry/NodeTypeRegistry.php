<?php
declare(strict_types=1);

namespace madong\stepcore\registry;

use madong\stepcore\contracts\NodeTypeHandler;
use madong\stepcore\exception\ConfigurationException;

/** 节点类型注册中心（借鉴 workflow NodeParserRegistry）：新增类型零改引擎。 */
final class NodeTypeRegistry
{
    /** @var array<string, array{property_schema:array<string,mixed>,handler:NodeTypeHandler,meta:array<string,mixed>}> */
    private array $types = [];

    /** @param array<string, mixed> $propertySchema @param array<string, mixed> $meta */
    public function register(string $code, array $propertySchema, NodeTypeHandler $handler, array $meta = []): void
    {
        if ($code === '') {
            throw new ConfigurationException('mes_flow_node_type_code_required');
        }
        if (isset($this->types[$code])) {
            throw new ConfigurationException('mes_flow_node_type_duplicated:' . $code);
        }
        $this->types[$code] = [
            'property_schema' => $propertySchema,
            'handler'         => $handler,
            'meta'            => $meta,
        ];
    }

    public function has(string $code): bool
    {
        return isset($this->types[$code]);
    }

    public function handler(string $code): NodeTypeHandler
    {
        if (!isset($this->types[$code])) {
            throw new ConfigurationException('mes_flow_node_type_unknown:' . $code);
        }
        return $this->types[$code]['handler'];
    }

    /** @return array<string, mixed> */
    public function propertySchema(string $code): array
    {
        return $this->types[$code]['property_schema'] ?? [];
    }

    /** @return array<string, array<string, mixed>> 供 GET /mes/route/designer/meta 下发 */
    public function describe(): array
    {
        $out = [];
        foreach ($this->types as $code => $entry) {
            $out[$code] = array_merge(
                ['code' => $code, 'property_schema' => $entry['property_schema']],
                $entry['meta'],
            );
        }
        return $out;
    }

    public function count(): int
    {
        return count($this->types);
    }
}

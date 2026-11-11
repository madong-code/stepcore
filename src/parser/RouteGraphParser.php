<?php
declare(strict_types=1);

namespace madong\stepcore\parser;

use madong\stepcore\enum\TransitionType;
use madong\stepcore\exception\ConfigurationException;
use madong\stepcore\model\RouteEdge;
use madong\stepcore\model\RouteGraph;
use madong\stepcore\model\RouteNode;

/**
 * 路线图解析器（P3-01）：宿主快照（RouteService::routeSnapshot 结构）→ RouteGraph。
 *
 * 条件白名单 DSL（02 ADR-05）：只允许登记过的键，未知键直接抛配置错误（U-BE-TR-013）。
 */
final class RouteGraphParser
{
    public const CONDITION_KEYS = ['auto_on_ng', 'defect_codes', 'qty_below', 'yield_below', 'branch_by'];

    /** 兼容的快照字段名（processes/transitions；transition 的 type 支持 int|BackedEnum） */
    public static function fromSnapshot(array $snapshot): RouteGraph
    {
        $nodes = [];
        foreach ($snapshot['processes'] ?? [] as $p) {
            $seq  = (int) $p['seq'];
            $key  = (string) ($p['node_key'] ?? $p['code'] ?? $p['id'] ?? $seq);
            if (isset($nodes[$seq])) {
                // 脏数据：重复 seq 不静默覆盖（U-BE-RG-010）
                throw new ConfigurationException('mes_route_topo_duplicate_seq');
            }
            $nodes[$seq] = new RouteNode(
                seq:       $seq,
                key:       $key,
                processId: (string) ($p['id'] ?? $p['process_id'] ?? ''),
                attrs:     $p,
            );
        }
        $edges = [];
        foreach ($snapshot['transitions'] ?? [] as $t) {
            $type = TransitionType::fromMixed($t['type'] ?? TransitionType::Straight);
            if (!in_array($type, TransitionType::cases(), true)) {
                throw new ConfigurationException('mes_route_transition_type_invalid');
            }
            $edges[] = new RouteEdge(
                key:       (string) ($t['edge_key'] ?? $t['id'] ?? ''),
                fromSeq:   (int) $t['from_seq'],
                toSeq:     (int) $t['to_seq'],
                type:      $type,
                weight:    ($t['weight'] ?? null) === null ? null : (int) $t['weight'],
                condition: self::normalizeCondition($t['condition'] ?? null),
            );
        }
        return new RouteGraph($nodes, $edges);
    }

    /**
     * 条件归一化：JSON 字符串/数组/null → 数组；未知键抛配置异常（不静默）。
     * @return array<string, mixed>
     */
    public static function normalizeCondition(mixed $condition): array
    {
        if ($condition === null || $condition === '' || $condition === 'null') {
            return [];
        }
        if (is_string($condition)) {
            $decoded = json_decode($condition, true);
            if (!is_array($decoded)) {
                throw new ConfigurationException('mes_route_transition_condition_invalid');
            }
            $condition = $decoded;
        }
        if (!is_array($condition)) {
            throw new ConfigurationException('mes_route_transition_condition_invalid');
        }
        foreach (array_keys($condition) as $k) {
            if (!in_array((string) $k, self::CONDITION_KEYS, true)) {
                throw new ConfigurationException('mes_route_transition_condition_unknown_key:' . (string) $k);
            }
        }
        return $condition;
    }
}

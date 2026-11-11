<?php
declare(strict_types=1);

namespace madong\stepcore\parser;

use madong\stepcore\exception\ConfigurationException;
use madong\stepcore\model\FlowEdge;
use madong\stepcore\model\FlowGraph;
use madong\stepcore\model\FlowNode;

/**
 * v2 图解析器：schema_version 判别 + v1→v2 内存转换 + graph_hash 规范化。
 * v1 转换用于兼容读取与一次性回填；不落库、不重写历史快照。
 */
final class FlowGraphParser
{
    private const KIND_TO_TYPE = [
        1 => 'step', 2 => 'start', 3 => 'end', 4 => 'decision',
        5 => 'split', 6 => 'join', 7 => 'fa_rework',
    ];

    private const TYPE_TO_MODE = [
        1 => FlowEdge::MODE_STRAIGHT,
        2 => FlowEdge::MODE_BRANCH,
        3 => FlowEdge::MODE_JOIN,
        4 => FlowEdge::MODE_REWORK,
    ];

    /** v1 节点中需要迁入 properties 的业务字段 */
    private const NODE_BUSINESS_KEYS = [
        'code', 'station_type', 'work_center_id', 'scan_mode', 'is_key_site',
        'qc_gate', 'allow_rework', 'max_rework_times', 'rework_overflow',
        'allow_skip', 'is_assembly', 'outsourced', 'standard_hours',
        'route_memo', 'step_group', 'custom_type',
    ];

    /** @param array<string, mixed> $content */
    public function parse(array $content): FlowGraph
    {
        $version = (int) ($content['schema_version'] ?? 1);
        return $version >= 2 ? $this->fromV2($content) : $this->fromV1($content);
    }

    /** @param array<string, mixed> $data */
    public function fromV2(array $data): FlowGraph
    {
        $nodes = [];
        foreach ((array) ($data['nodes'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $nodes[] = new FlowNode(
                (string) ($raw['node_key'] ?? ''),
                (string) ($raw['type'] ?? 'step'),
                (string) ($raw['name'] ?? ''),
                (array) ($raw['properties'] ?? []),
                (array) ($raw['steps'] ?? []),
                (array) ($raw['ext'] ?? []),
            );
        }

        $edges = [];
        foreach ((array) ($data['transitions'] ?? $data['edges'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $edges[] = new FlowEdge(
                (string) ($raw['edge_key'] ?? $raw['key'] ?? ''),
                (string) ($raw['from_node_key'] ?? ''),
                (string) ($raw['to_node_key'] ?? ''),
                (string) ($raw['mode'] ?? FlowEdge::MODE_STRAIGHT),
                (array) ($raw['properties'] ?? []),
                (array) ($raw['ext'] ?? []),
            );
        }
        return new FlowGraph($nodes, $edges);
    }

    /**
     * v1 → v2：seq 定位键换成 node_key，扁平字段归入 properties，
     * 并把「无显式出边则 seq 升序顺延」的隐式规则物化为显式 straight 边。
     *
     * @param array<string, mixed> $data
     */
    public function fromV1(array $data): FlowGraph
    {
        $rawNodes = array_values((array) ($data['nodes'] ?? []));
        $seqToKey = [];
        foreach ($rawNodes as $i => $raw) {
            $seq = (int) ($raw['seq'] ?? (($i + 1) * 10));
            $key = (string) ($raw['key'] ?? ('nd_' . $seq));
            $seqToKey[$seq] = $key;
        }
        ksort($seqToKey, SORT_NUMERIC);

        $nodes = [];
        foreach ($rawNodes as $i => $raw) {
            $seq = (int) ($raw['seq'] ?? (($i + 1) * 10));
            $key = $seqToKey[$seq];
            $props = [];
            foreach (self::NODE_BUSINESS_KEYS as $field) {
                if (array_key_exists($field, $raw)) {
                    $props[$field] = $raw[$field];
                }
            }
            $reworkTo = $raw['rework_to_seq'] ?? null;
            $props['rework_to_node_key'] = ($reworkTo === null || (int) $reworkTo === 0)
                ? null
                : ($seqToKey[(int) $reworkTo] ?? null);
            $props['hooks'] = [
                'pre'  => (array) ($raw['pre_hooks'] ?? []),
                'post' => (array) ($raw['post_hooks'] ?? []),
            ];
            $props['guards'] = [];

            $steps = [];
            foreach ((array) ($raw['steps'] ?? []) as $j => $step) {
                $steps[] = [
                    'step_key'   => (string) ($step['step_key'] ?? $step['id'] ?? ('st_' . ($j + 1))),
                    'properties' => [
                        'content'       => $step['content'] ?? '',
                        'device_params' => $step['device_params'] ?? [],
                        'attachments'   => $step['attachments'] ?? [],
                    ],
                ];
            }

            $kind = (int) ($raw['node_kind'] ?? 1);
            $nodes[] = new FlowNode(
                $key,
                self::KIND_TO_TYPE[$kind] ?? 'step',
                (string) ($raw['name'] ?? $key),
                $props,
                $steps,
            );
        }

        $nodeMap = [];
        foreach ($nodes as $node) {
            $nodeMap[$node->nodeKey] = $node;
        }

        $edges = [];
        $hasExplicitOut = [];
        foreach ((array) ($data['edges'] ?? $data['transitions'] ?? []) as $i => $raw) {
            $fromKey = (string) ($raw['from_node_key'] ?? $raw['from_node'] ?? ($seqToKey[(int) ($raw['from_seq'] ?? 0)] ?? ''));
            $toKey   = (string) ($raw['to_node_key'] ?? $raw['to_node'] ?? ($seqToKey[(int) ($raw['to_seq'] ?? 0)] ?? ''));
            if ($fromKey === '' || $toKey === '' || !isset($nodeMap[$fromKey], $nodeMap[$toKey])) {
                continue;
            }
            $type = (int) ($raw['type'] ?? 1);
            $mode = self::TYPE_TO_MODE[$type] ?? FlowEdge::MODE_STRAIGHT;
            $cond = $raw['condition'] ?? [];
            if (is_string($cond)) {
                $cond = json_decode($cond, true) ?: [];
            }
            $edgeProps = [
                'trigger'   => $mode === FlowEdge::MODE_REWORK
                    ? ['type' => 'auto_ng']
                    : ['type' => 'manual'],
                'condition' => ['expr' => null, 'rules' => [], 'legacy' => $cond],
                'priority'  => 100,
                'weight'    => $raw['weight'] ?? null,
                'guards'    => [],
                'actions'   => [],
                'label'     => $raw['label'] ?? '',
                'style'     => $raw['style'] ?? [],
                'enabled'   => (int) ($raw['status'] ?? 1) === 1,
            ];
            $edges[] = new FlowEdge(
                (string) ($raw['edge_key'] ?? $raw['key'] ?? ('ed_' . ($i + 1))),
                $fromKey,
                $toKey,
                $mode,
                $edgeProps,
            );
            if ($mode !== FlowEdge::MODE_REWORK) {
                $hasExplicitOut[$fromKey] = true;
            }
        }

        // 隐式 seq 顺延物化：无任何显式前向出边的节点连到下一个 seq 节点
        $keys = array_values($seqToKey);
        foreach ($keys as $i => $fromKey) {
            if (isset($hasExplicitOut[$fromKey]) || !isset($keys[$i + 1])) {
                continue;
            }
            $toKey = $keys[$i + 1];
            // 已是显式回边目标的节点不重复补直线边
            $edges[] = new FlowEdge(
                'ed_materialized_' . $fromKey . '_' . $toKey,
                $fromKey,
                $toKey,
                FlowEdge::MODE_STRAIGHT,
                [
                    'trigger'   => ['type' => 'manual'],
                    'condition' => ['expr' => null, 'rules' => []],
                    'priority'  => 100,
                    'enabled'   => true,
                    'materialized' => true,
                ],
            );
        }

        return new FlowGraph($nodes, $edges);
    }

    /** 规范化 JSON 的 sha1（布局 viewport 与 hash 自身不参与） */
    public static function graphHash(array $data): string
    {
        unset($data['meta']['graph_hash'], $data['layout']['viewport']);
        $data = self::ksortRecursive($data);
        if (isset($data['nodes']) && is_array($data['nodes'])) {
            usort($data['nodes'], fn ($a, $b) => strcmp((string) ($a['node_key'] ?? ''), (string) ($b['node_key'] ?? '')));
            foreach ($data['nodes'] as &$n) {
                if (isset($n['steps']) && is_array($n['steps'])) {
                    usort($n['steps'], fn ($a, $b) => strcmp((string) ($a['step_key'] ?? ''), (string) ($b['step_key'] ?? '')));
                }
            }
            unset($n);
        }
        if (isset($data['transitions']) && is_array($data['transitions'])) {
            usort($data['transitions'], static function ($a, $b) {
                return [
                    (string) ($a['from_node_key'] ?? ''),
                    (string) ($a['to_node_key'] ?? ''),
                    (string) ($a['edge_key'] ?? ''),
                ] <=> [
                    (string) ($b['from_node_key'] ?? ''),
                    (string) ($b['to_node_key'] ?? ''),
                    (string) ($b['edge_key'] ?? ''),
                ];
            });
        }
        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        );
        if ($json === false) {
            throw new ConfigurationException('mes_flow_graph_hash_failed');
        }
        return sha1($json);
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private static function ksortRecursive(array $value): array
    {
        foreach ($value as &$v) {
            if (is_array($v)) {
                $v = self::ksortRecursive($v);
            }
        }
        unset($v);
        ksort($value);
        return $value;
    }
}

<?php
/**
 * stepcore demo · API 端点
 *
 * - POST /api/parse  接收 v2 路线 JSON → 解析为 FlowGraph → 校验 → 从起点走查
 * - GET  /api/schema 返回 v2 JSON Schema（供设计器下拉参考）
 *
 * 纯前端控制器，无框架依赖；前端控制器 public/index.php 在匹配到 /api/ 时引入本文件。
 */

declare(strict_types=1);

namespace madong\stepcore\demo;

require_once __DIR__ . '/bootstrap.php';

use madong\stepcore\model\FlowContext;
use madong\stepcore\parser\FlowGraphParser;

$uri    = \parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

\header('Content-Type: application/json; charset=utf-8');
\header('Access-Control-Allow-Origin: *');
\header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
\header('Access-Control-Allow-Headers: Content-Type');

if ($method === 'OPTIONS') {
    \http_response_code(204);
    exit;
}

if ($uri === '/api/schema') {
    $schema = @\file_get_contents(schemaPath());
    echo $schema !== false ? $schema : \json_encode(['error' => 'schema not found']);
    exit;
}

if ($uri === '/api/parse') {
    if ($method !== 'POST') {
        \http_response_code(405);
        echo \json_encode(['ok' => false, 'error' => 'method not allowed']);
        exit;
    }
    $raw  = \file_get_contents('php://input');
    $data = \json_decode($raw, true);
    if (!\is_array($data)) {
        \http_response_code(400);
        echo \json_encode(['ok' => false, 'error' => 'invalid json']);
        exit;
    }

    try {
        $graph = parser()->parse($data);
    } catch (\Throwable $e) {
        \http_response_code(400);
        echo \json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }

    $issues = $graph->validate();

    $walkthrough = [];
    $start       = $graph->startNodeKey();
    if ($start !== null) {
        $resolver = engine();
        $cur      = $start;
        $visited  = [];
        while ($cur !== null && !isset($visited[$cur])) {
            $visited[$cur] = true;
            $node = $graph->node($cur);
            $ctx  = new FlowContext(action: 'pass', nodeKey: $cur);
            try {
                $result = $resolver->resolve($graph, $cur, $ctx);
            } catch (\Throwable $e) {
                $walkthrough[] = [
                    'from'      => $cur,
                    'from_name' => $node ? $node->name : $cur,
                    'type'      => 'error',
                    'detail'    => $e->getMessage(),
                ];
                break;
            }

            $step = [
                'from'      => $cur,
                'from_name' => $node ? $node->name : $cur,
                'type'      => $result->type,
                'to'        => $result->toNodeKey,
                'edge'      => $result->edgeKey,
                'targets'   => $result->v2Targets(),
                'blocked'   => $result->type === 'blocked' ? $result->errorCode : null,
            ];
            $walkthrough[] = $step;

            if ($result->type === 'tail' || $result->type === 'blocked' || $result->type === 'error') {
                break;
            }
            if ($result->type === 'straight' || $result->type === 'rework') {
                $cur = $result->toNodeKey;
            } elseif ($result->type === 'branch') {
                $targets = $result->v2Targets();
                $cur     = $targets[0]['to_node_key'] ?? null;
            } else {
                break;
            }
        }
    }

    echo \json_encode([
        'ok'          => true,
        'node_count'  => $graph->nodeCount(),
        'edge_count'  => \count($graph->edges()),
        'start_node'  => $start,
        'issues'      => $issues,
        'graph_hash'  => FlowGraphParser::graphHash($data),
        'walkthrough' => $walkthrough,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

\http_response_code(404);
echo \json_encode(['error' => 'not found']);

<?php
declare(strict_types=1);

namespace madong\stepcore\route;

use madong\stepcore\exception\ConfigurationException;
use madong\stepcore\model\FlowEdge;
use madong\stepcore\model\FlowGraph;
use madong\stepcore\rollback\RollbackContext;

/** 回退目标解析：逆序查变迁日志 / 显式回边 / 指定节点重定位。 */
final class RollbackResolver
{
    /** 不移动令牌的模式 */
    private const STATIC_MODES = ['reversal', 'replay', 'cancel'];

    /** @throws ConfigurationException */
    public function resolve(FlowGraph $graph, RollbackContext $ctx): string
    {
        if (in_array($ctx->mode, self::STATIC_MODES, true)) {
            return '';
        }

        if ($ctx->mode === 'jump') {
            $target = $ctx->targetNodeKey;
            if ($target === '' || !$graph->has($target)) {
                throw new ConfigurationException('mes_flow_rollback_target_invalid');
            }
            return $target;
        }

        if ($ctx->mode === 'step_back') {
            foreach ($ctx->history as $row) {
                $result = (int) ($row['result'] ?? 0);
                $mode   = (string) ($row['mode'] ?? '');
                if ($result !== 1 || in_array($mode, ['rollback', 'jump', 'cancel'], true)) {
                    continue;
                }
                $from = (string) ($row['from_node_key'] ?? '');
                if ($from !== '' && $graph->has($from)) {
                    return $from;
                }
            }
            throw new ConfigurationException('mes_flow_rollback_no_source');
        }

        if ($ctx->mode === 'rework') {
            $target = (string) ($ctx->origin->properties['rework_to_node_key'] ?? '');
            if ($target !== '' && $graph->has($target)) {
                return $target;
            }
            foreach ($graph->outEdgesFrom($ctx->fromNodeKey) as $edge) {
                if ($edge->mode === FlowEdge::MODE_REWORK) {
                    return $edge->toNodeKey;
                }
            }
            throw new ConfigurationException('mes_flow_rollback_no_rework_edge');
        }

        throw new ConfigurationException('mes_flow_rollback_mode_unknown:' . $ctx->mode);
    }
}

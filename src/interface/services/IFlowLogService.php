<?php
declare(strict_types=1);

namespace madong\stepcore\interface\services;

/**
 * 流转日志服务（宿主实现，mes_move_log）。
 */
interface IFlowLogService
{
    /** @param array<string, mixed> $entry @return array<string, mixed> */
    public function log(array $entry): array;
}

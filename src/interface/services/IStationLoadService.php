<?php
declare(strict_types=1);

namespace madong\stepcore\interface\services;

/**
 * 站点物料装载服务（宿主实现，转序前上料完整性校验；未装配站点场景可 no-op）。
 */
interface IStationLoadService
{
    /** @throws \RuntimeException 上料不完整时抛宿主异常（mes_scan_load_incomplete） */
    public function assertLoadComplete(string $bomId, string $routeId, string $processCode, string $woId): void;
}

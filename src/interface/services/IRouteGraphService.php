<?php
declare(strict_types=1);

namespace madong\stepcore\interface\services;

use madong\stepcore\model\RouteGraph;

/**
 * 路线图服务（宿主实现，P3-12）：route_id → 编译后的 RouteGraph（可含 revision 缓存）。
 */
interface IRouteGraphService
{
    public function loadGraph(string $routeId): RouteGraph;
}

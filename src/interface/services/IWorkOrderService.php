<?php
declare(strict_types=1);

namespace madong\stepcore\interface\services;

/**
 * 工单服务（宿主实现）：只读快照访问。
 */
interface IWorkOrderService
{
    /** @return array<string, mixed>|null */
    public function find(string $woId): ?array;
}

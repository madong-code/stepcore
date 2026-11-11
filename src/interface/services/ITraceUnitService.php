<?php
declare(strict_types=1);

namespace madong\stepcore\interface\services;

/**
 * 追溯单元服务（宿主实现）。
 */
interface ITraceUnitService
{
    /** @return array<string, mixed>|null */
    public function findByNo(string $unitNo): ?array;

    /** @return list<array<string, mixed>> 在指定流转卡上的在制单元 */
    public function unitsInCard(string $cardId): array;
}

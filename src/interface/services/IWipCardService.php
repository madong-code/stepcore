<?php
declare(strict_types=1);

namespace madong\stepcore\interface\services;

/**
 * 流转卡服务（宿主实现）。
 */
interface IWipCardService
{
    /** @return array<string, mixed>|null */
    public function cardOf(string $woId, string $processId): ?array;

    /** @return list<array<string, mixed>> */
    public function cardsOf(string $woId): array;

    /** @return array<string, mixed>|null */
    public function cardById(string $cardId): ?array;

    /** 指定流转卡上的在制单元快照（P3-08 汇合就绪判定用；只读）。
     * @return list<array<string, mixed>>
     */
    public function unitsInCard(string $cardId): array;
}

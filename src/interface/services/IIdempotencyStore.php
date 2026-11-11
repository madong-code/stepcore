<?php
declare(strict_types=1);

namespace madong\stepcore\interface\services;

/**
 * 幂等存储（包内规范，P3-10；宿主实现走 Redis SETNX + journal，02 ADR-13）。
 */
interface IIdempotencyStore
{
    /** 原子占位：首个到达者返回 true（并发同 key 后到者 false） */
    public function acquire(string $key, string $payloadHash, int $ttl): bool;

    /** 提交结果（仅占位成功后调用） */
    public function commit(string $key, string $payloadHash, array $result, int $ttl): void;

    /** @return array{payload:string,result:array}|null */
    public function get(string $key): ?array;

    /** 释放占位（动作失败允许同 key 重试） */
    public function release(string $key): void;
}

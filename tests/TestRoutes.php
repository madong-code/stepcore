<?php
declare(strict_types=1);

namespace madong\stepcore\Tests;

/**
 * 包级测试用路线快照工厂（与宿主 MesFixture 同构，但纯内存、无 DB）。
 */
final class TestRoutes
{
    /** @param array<string, mixed> $opts */
    public static function snapshot(string $shape, array $opts = []): array
    {
        [$processes, $transitions] = match ($shape) {
            'straight'      => self::straight(),
            'explicit'      => self::explicit(),
            'branch'        => self::branch($opts),
            'branch_join'   => self::branchJoin($opts),
            'rework_only'   => self::reworkOnly(),
            default         => throw new \InvalidArgumentException($shape),
        };
        $extra = $opts['extra_transition'] ?? null;
        if ($extra !== null) {
            $transitions[] = $extra;
        }
        return ['processes' => $processes, 'transitions' => $transitions];
    }

    /** 10→20→30→40 隐式直线（无显式边） */
    private static function straight(): array
    {
        $processes = [];
        foreach ([10, 20, 30, 40] as $i => $seq) {
            $processes[] = ['seq' => $seq, 'id' => 'p' . $seq, 'code' => 'P' . ($i + 1), 'name' => '工序' . ($i + 1)];
        }
        return [$processes, []];
    }

    /** 10→(type=1)→30→40，20 挂空 */
    private static function explicit(): array
    {
        $processes = [];
        foreach ([10, 20, 30, 40] as $i => $seq) {
            $processes[] = ['seq' => $seq, 'id' => 'p' . $seq, 'code' => 'P' . ($i + 1)];
        }
        return [$processes, [['from_seq' => 10, 'to_seq' => 30, 'type' => 1]]];
    }

    /** 10 分叉 70/30 → 20 / 30 */
    private static function branch(array $opts): array
    {
        $processes = [];
        foreach ([10, 20, 30] as $i => $seq) {
            $processes[] = ['seq' => $seq, 'id' => 'p' . $seq, 'code' => 'P' . ($i + 1)];
        }
        // 默认 70/30；显式传 'weight'=>null 表示全空（均分用例）
        $useCustom = !array_key_exists('weight', $opts) || $opts['weight'] !== null;
        $w1 = $useCustom ? (int) ($opts['w1'] ?? 70) : null;
        $w2 = $useCustom ? (int) ($opts['w2'] ?? 30) : null;
        return [$processes, [
            ['from_seq' => 10, 'to_seq' => 20, 'type' => 2, 'weight' => $w1],
            ['from_seq' => 10, 'to_seq' => 30, 'type' => 2, 'weight' => $w2],
        ]];
    }

    /** 10 分叉 70/30 → 20/30 →(join)→ 40 → 50 */
    private static function branchJoin(array $opts): array
    {
        $processes = [];
        foreach ([10, 20, 30, 40, 50] as $i => $seq) {
            $processes[] = ['seq' => $seq, 'id' => 'p' . $seq, 'code' => 'P' . ($i + 1)];
        }
        return [$processes, [
            ['from_seq' => 10, 'to_seq' => 20, 'type' => 2, 'weight' => 70],
            ['from_seq' => 10, 'to_seq' => 30, 'type' => 2, 'weight' => 30],
            ['from_seq' => 20, 'to_seq' => 40, 'type' => 3],
            ['from_seq' => 30, 'to_seq' => 40, 'type' => 3],
        ]];
    }

    /** 10→20→30，30 只有返工回边 */
    private static function reworkOnly(): array
    {
        $processes = [];
        foreach ([10, 20, 30] as $i => $seq) {
            $processes[] = ['seq' => $seq, 'id' => 'p' . $seq, 'code' => 'P' . ($i + 1)];
        }
        return [$processes, [
            ['from_seq' => 30, 'to_seq' => 20, 'type' => 4, 'condition' => ['auto_on_ng' => true]],
        ]];
    }
}

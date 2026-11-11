<?php
declare(strict_types=1);

namespace madong\stepcore\enum;

/**
 * 转移边类型（与宿主 mes_route_transition.type 及 plugin\mes\app\enum\RouteTransitionType 对齐）。
 * 包内自带一份，避免依赖宿主枚举（依赖方向：宿主 → 包，单向）。
 */
enum TransitionType: int
{
    /** 直线 */
    case Straight = 1;
    /** 并行分叉 */
    case Branch = 2;
    /** 汇合 */
    case Join = 3;
    /** 返工回边 */
    case Rework = 4;

    public static function fromMixed(int|string|\BackedEnum $value): self
    {
        $int = $value instanceof \BackedEnum ? (int) $value->value : (int) $value;
        return self::from($int);
    }
}

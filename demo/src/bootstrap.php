<?php
/**
 * stepcore demo · 极简引导文件
 *
 * - 提供 madong\stepcore 的 PSR-4 自动加载（避免依赖 composer install / vendor）
 * - 提供装配好的引擎解析器与图解析器工厂
 *
 * 该文件被 public/index.php 与 src/api.php 以 require_once 引入，幂等。
 */

declare(strict_types=1);

namespace madong\stepcore\demo;

use madong\stepcore\parser\ConditionEvaluator;
use madong\stepcore\parser\FlowGraphParser;
use madong\stepcore\route\FlowTransitionResolver;
use madong\stepcore\route\StableHashBranchStrategy;

if (!function_exists('madong\\stepcore\\demo\\engine')) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'madong\\stepcore\\';
        if (strncmp($class, $prefix, \strlen($prefix)) !== 0) {
            return;
        }
        $rel  = \substr($class, \strlen($prefix));
        $file = __DIR__ . '/../../src/' . \str_replace('\\', '/', $rel) . '.php';
        if (\is_file($file)) {
            require $file;
        }
    });

    function engine(): FlowTransitionResolver
    {
        $conditions = new ConditionEvaluator();
        $branch     = new StableHashBranchStrategy();
        return new FlowTransitionResolver($conditions, $branch);
    }

    function parser(): FlowGraphParser
    {
        return new FlowGraphParser();
    }

    function schemaPath(): string
    {
        $p = \realpath(__DIR__ . '/../../src/schema/route-graph-v2.json');
        return $p === false ? '' : $p;
    }
}

<?php
declare(strict_types=1);

/**
 * StepCore 包级测试引导：
 * 复用 backend/vendor 的 PHPUnit；包源码直挂 PSR-4（path repo symlink 未就绪时也可跑）。
 */
$loader = require __DIR__ . '/../../../backend/vendor/autoload.php';
$loader->addPsr4('madong\\stepcore\\', dirname(__DIR__) . '/src/');
$loader->addPsr4('madong\\stepcore\\Tests\\', __DIR__ . '/');

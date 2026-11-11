<?php
declare(strict_types=1);

namespace madong\stepcore\contracts;

use madong\stepcore\registry\ExtensionRegistry;

/** 模块生命周期：discover → register → boot → run → shutdown。 */
interface FlowModule
{
    public function register(ExtensionRegistry $registry): void;

    public function boot(): void;

    public function shutdown(): void;
}

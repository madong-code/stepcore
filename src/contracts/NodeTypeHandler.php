<?php
declare(strict_types=1);

namespace madong\stepcore\contracts;

use madong\stepcore\model\FlowContext;
use madong\stepcore\model\FlowResult;

/** 节点类型处理器：新增节点类型只需提供本实现 + propertySchema，引擎零改动。 */
interface NodeTypeHandler
{
    /** 令牌抵达：校验 properties、创建任务与历史快照 */
    public function onEnter(FlowContext $ctx): void;

    /** 节点执行：过站/条件路由/分叉汇合 */
    public function onExecute(FlowContext $ctx): FlowResult;

    /** 令牌离开：归档任务、写变迁日志 */
    public function onLeave(FlowContext $ctx): void;
}

<?php
declare(strict_types=1);

namespace madong\stepcore\model;

/**
 * 流程上下文（P3-02）：一次过站/转序动作的全部输入，值对象（withX 派生副本）。
 */
final class FlowContext
{
    /**
     * @param list<string> $unitNos 批量单元号（rework/transfer 多单元）
     * @param array<string, mixed> $unit  单元快照（宿主注入）
     * @param array<string, mixed> $card  流转卡快照（宿主注入）
     * @param array<string, mixed> $extra 动作私有输入
     */
    public function __construct(
        public readonly string $action,
        public readonly string $woId = '',
        public readonly string $routeId = '',
        public readonly string $processId = '',
        public readonly int $seq = 0,
        public readonly array $unitNos = [],
        public readonly string $unitNo = '',
        public readonly array $unit = [],
        public readonly array $card = [],
        public readonly string $operator = '',
        public readonly string $terminalCode = '',
        public readonly string $defectCode = '',
        public readonly string $remark = '',
        public readonly ?int $toSeq = null,
        public readonly string $seed = '',
        public readonly ?string $idempotencyKey = null,
        public readonly array $extra = [],
        // ---- v2（node_key + 变迁编排）字段；追加在末尾以兼容既有位置参数调用 ----
        public readonly string $nodeKey = '',
        public readonly string $instanceId = '',
        public readonly string $taskId = '',
        public readonly string $tokenId = '',
        public readonly string $trigger = 'manual',
        public readonly array $variable = [],
        public readonly array $properties = [],
        public readonly string $reasonCode = '',
        public readonly string $clientRequestId = '',
        public readonly string $targetNodeKey = '',
        public readonly string $rollbackMode = '',
        public readonly ?object $graph = null,
    ) {
    }

    /** v2 当前定位键：优先 node_key，兼容期回落 seq */
    public function positionKey(): string
    {
        return $this->nodeKey !== '' ? $this->nodeKey : (string) $this->seq;
    }

    /** 分叉选支种子：缺省用单元号（同 SN 恒定同支） */
    public function branchSeed(): string
    {
        return $this->seed !== '' ? $this->seed : $this->unitNo;
    }

    public function with(array $overrides): self
    {
        $vars = get_object_vars($this);
        foreach ($overrides as $k => $v) {
            if (!array_key_exists($k, $vars)) {
                throw new \InvalidArgumentException('unknown FlowContext field: ' . $k);
            }
            $vars[$k] = $v;
        }
        return new self(...$vars);
    }
}

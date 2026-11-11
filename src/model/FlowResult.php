<?php
declare(strict_types=1);

namespace madong\stepcore\model;

/**
 * 流程结果（P3-02）：统一返回结构，toLegacyArray() 兼容旧 TransitService::resolveNext 响应。
 */
final class FlowResult
{
    /**
     * @param list<array{seq:int,weight:int,process:?array<string,mixed>}> $branches
     * @param list<array{name:string,errorCode:?string,detail:?string}>    $warnings
     * @param array<string, mixed>                                         $extra
     */
    public function __construct(
        public readonly string $type,
        public readonly ?int $seq = null,
        public readonly ?array $process = null,
        public readonly array $branches = [],
        public readonly int $totalWeight = 0,
        public readonly ?string $branchKey = null,
        public readonly bool $replayed = false,
        public readonly array $warnings = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorDetail = null,
        public readonly array $extra = [],
        // ---- v2 字段（追加在末尾，兼容 get_object_vars + new FlowResult(...$vars)）----
        public readonly ?string $nodeKey = null,
        public readonly ?string $edgeKey = null,
        public readonly ?string $toNodeKey = null,
        public readonly ?string $rollbackMode = null,
        public readonly ?string $reasonCode = null,
    ) {
    }

    public static function tail(): self
    {
        return new self('tail');
    }

    /** @param array<string, mixed>|null $process */
    public static function straight(int $seq, ?array $process = null): self
    {
        return new self('straight', seq: $seq, process: $process);
    }

    /** @param list<array{seq:int,weight:int,process:?array<string,mixed>}> $branches */
    public static function branch(array $branches, int $totalWeight): self
    {
        return new self('branch', branches: $branches, totalWeight: $totalWeight);
    }

    public static function rework(int $toSeq): self
    {
        return new self('rework', seq: $toSeq);
    }

    public static function blocked(string $errorCode, string $detail = ''): self
    {
        return new self('blocked', errorCode: $errorCode, errorDetail: $detail);
    }

    /** v2：按 node_key 直线推进 */
    public static function straightTo(string $toNodeKey, ?string $edgeKey = null): self
    {
        return new self('straight', toNodeKey: $toNodeKey, edgeKey: $edgeKey);
    }

    /**
     * v2：并行分叉目标列表。
     * @param list<array{to_node_key:string,edge_key:?string,weight:?int}> $targets
     */
    public static function branchTo(array $targets, int $totalWeight = 0): self
    {
        return new self('branch', totalWeight: $totalWeight, extra: ['targets' => $targets]);
    }

    /** @return list<array{to_node_key:string,edge_key:?string,weight:?int}> */
    public function v2Targets(): array
    {
        return (array) ($this->extra['targets'] ?? []);
    }

    /** v2：返工/回边到指定 node_key */
    public static function reworkTo(string $toNodeKey, ?string $edgeKey = null): self
    {
        return new self('rework', toNodeKey: $toNodeKey, edgeKey: $edgeKey);
    }

    /** v2：回退（step_back / jump / reversal / cancel 共用 type=rollback） */
    public static function rollback(string $toNodeKey, string $mode = 'step_back', ?string $edgeKey = null): self
    {
        return new self('rollback', toNodeKey: $toNodeKey, edgeKey: $edgeKey, rollbackMode: $mode);
    }

    /** v2：重复扫码/超时重放，幂等返回首次结果，不产生新变迁 */
    public static function replay(self $original): self
    {
        $vars = get_object_vars($original);
        $vars['replayed'] = true;
        return new self(...$vars);
    }

    /** v2：不可恢复异常，实例/任务终态 */
    public static function canceled(string $reasonCode = ''): self
    {
        return new self('cancel', rollbackMode: 'cancel', reasonCode: $reasonCode);
    }

    /** 兼容旧 resolveNext 输出（影子比对/灰度契约，07-transit.md §3.2） */
    public function toLegacyArray(): array
    {
        if ($this->type === 'tail') {
            return ['type' => 'tail'];
        }
        if ($this->type === 'branch') {
            return [
                'type'         => 'branch',
                'branches'     => $this->branches,
                'total_weight' => $this->totalWeight,
            ];
        }
        if ($this->type === 'rework') {
            return ['type' => 'rework', 'seq' => $this->seq];
        }
        if ($this->type === 'blocked') {
            return ['type' => 'blocked', 'error_code' => $this->errorCode];
        }
        return ['type' => 'straight', 'seq' => $this->seq, 'process' => $this->process];
    }
}

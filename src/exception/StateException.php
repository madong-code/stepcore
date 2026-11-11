<?php
declare(strict_types=1);

namespace madong\stepcore\exception;

/**
 * 非法状态迁移（U-BE-SM-002/006/009/010：错误信息含 from/to/event，错误码 mes_flow_state_invalid）。
 */
class StateException extends StepCoreException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $event = '',
    ) {
        parent::__construct(sprintf(
            'mes_flow_state_invalid: %s -> %s%s',
            $from !== '' ? $from : '(none)',
            $to,
            $event !== '' ? ' (event=' . $event . ')' : ''
        ));
    }
}

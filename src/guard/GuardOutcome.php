<?php
declare(strict_types=1);

namespace madong\stepcore\guard;

/**
 * 守卫结果（值对象）。status: ok|warn|block|error。
 */
final class GuardOutcome
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $errorCode = null,
        public readonly ?string $detail = null,
        public readonly array $data = [],
    ) {
    }

    public static function ok(): self
    {
        return new self('ok');
    }

    public static function warn(string $errorCode, string $detail = '', array $data = []): self
    {
        return new self('warn', $errorCode, $detail, $data);
    }

    public static function block(string $errorCode, string $detail = '', array $data = []): self
    {
        return new self('block', $errorCode, $detail, $data);
    }

    public static function error(string $errorCode, string $detail = ''): self
    {
        return new self('error', $errorCode, $detail);
    }

    public function blocked(): bool
    {
        return in_array($this->status, ['block', 'error'], true);
    }

    /** @return array{name:?string,errorCode:?string,detail:?string} */
    public function toArray(?string $name = null): array
    {
        return [
            'name'       => $name,
            'status'     => $this->status,
            'error_code' => $this->errorCode,
            'detail'     => $this->detail,
        ];
    }
}

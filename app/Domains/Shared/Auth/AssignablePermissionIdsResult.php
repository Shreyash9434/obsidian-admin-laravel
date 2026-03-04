<?php

declare(strict_types=1);

namespace App\Domains\Shared\Auth;

final readonly class AssignablePermissionIdsResult
{
    /**
     * @param  list<int>  $permissionIds
     */
    private function __construct(
        private bool $ok,
        private string $code,
        private string $message,
        private array $permissionIds = [],
    ) {}

    /**
     * @param  list<int>  $permissionIds
     */
    public static function success(array $permissionIds): self
    {
        return new self(
            ok: true,
            code: '0000',
            message: 'ok',
            permissionIds: $permissionIds,
        );
    }

    public static function failure(string $code, string $message): self
    {
        return new self(
            ok: false,
            code: $code,
            message: $message,
        );
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function failed(): bool
    {
        return ! $this->ok;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * @return list<int>
     */
    public function permissionIds(): array
    {
        return $this->permissionIds;
    }
}

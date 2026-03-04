<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Services;

use App\Domains\Access\Models\Role;
use App\Domains\Access\Models\User;
use App\Domains\Shared\Auth\RoleScopeContext;
use App\Domains\Shared\Auth\TenantContext;
use App\Domains\Tenant\Models\Tenant;
use Illuminate\Http\Request;

class TenantContextService
{
    public const SUCCESS_CODE = '0000';

    public const FORBIDDEN_CODE = '1003';

    private const NO_TENANTS_NAME = 'No Tenants';

    private const PLATFORM_TENANT_NAME = 'Platform';

    public function resolveTenantContext(Request $request, User $user): TenantContext
    {
        $user->loadMissing('role:id,code,level,status,tenant_id', 'tenant:id,name,status');

        if ($this->isSuperAdmin($user)) {
            $activeTenants = Tenant::query()
                ->where('status', '1')
                ->orderBy('id')
                ->get(['id', 'name']);

            $headerTenantId = $this->headerTenantId($request);
            $currentTenant = $headerTenantId > 0 ? $activeTenants->firstWhere('id', $headerTenantId) : null;
            if ($headerTenantId > 0 && ! $currentTenant) {
                return TenantContext::failure(self::FORBIDDEN_CODE, 'Selected tenant is invalid or inactive');
            }

            /** @var list<array{tenantId: string, tenantName: string}> $tenantOptions */
            $tenantOptions = $activeTenants
                ->map(static function (Tenant $tenant): array {
                    return [
                        'tenantId' => (string) $tenant->id,
                        'tenantName' => (string) $tenant->name,
                    ];
                })
                ->values()
                ->all();

            return TenantContext::success(
                tenantId: $currentTenant ? (int) $currentTenant->id : null,
                tenantName: $currentTenant ? (string) $currentTenant->name : self::NO_TENANTS_NAME,
                tenants: $tenantOptions,
                code: self::SUCCESS_CODE,
                message: 'ok'
            );
        }

        if ($this->isPlatformScopedUser($user)) {
            return TenantContext::success(
                tenantId: null,
                tenantName: self::PLATFORM_TENANT_NAME,
                tenants: [],
                code: self::SUCCESS_CODE,
                message: 'ok'
            );
        }

        if (! $user->tenant_id || ! $user->tenant || $user->tenant->status !== '1') {
            return TenantContext::failure(self::FORBIDDEN_CODE, 'Tenant is inactive');
        }

        return TenantContext::success(
            tenantId: (int) $user->tenant->id,
            tenantName: (string) $user->tenant->name,
            tenants: [[
                'tenantId' => (string) $user->tenant->id,
                'tenantName' => (string) $user->tenant->name,
            ]],
            code: self::SUCCESS_CODE,
            message: 'ok'
        );
    }

    public function resolveRoleScope(Request $request, User $user): RoleScopeContext
    {
        $user->loadMissing('role:id,code,level,status,tenant_id', 'tenant:id,status');

        if ($this->isSuperAdmin($user)) {
            $activeTenantIds = Tenant::query()
                ->where('status', '1')
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $headerTenantId = $this->headerTenantId($request);
            if ($headerTenantId > 0 && ! in_array($headerTenantId, $activeTenantIds, true)) {
                return RoleScopeContext::failure(self::FORBIDDEN_CODE, 'Selected tenant is invalid or inactive');
            }
            $tenantId = in_array($headerTenantId, $activeTenantIds, true) ? $headerTenantId : null;

            return RoleScopeContext::success(
                tenantId: $tenantId,
                isSuper: true
            );
        }

        if ($this->isPlatformScopedUser($user)) {
            return RoleScopeContext::success(
                tenantId: null,
                isSuper: false
            );
        }

        if (! $user->tenant_id || ! $user->tenant || $user->tenant->status !== '1') {
            return RoleScopeContext::failure(self::FORBIDDEN_CODE, 'Tenant is inactive');
        }

        return RoleScopeContext::success(
            tenantId: (int) $user->tenant_id,
            isSuper: false
        );
    }

    public function isSuperAdmin(User $user): bool
    {
        $user->loadMissing('role:id,code,level,status');

        $role = $user->getRelationValue('role');
        if (! $role instanceof Role) {
            return false;
        }

        return (string) $role->code === 'R_SUPER';
    }

    private function isPlatformScopedUser(User $user): bool
    {
        $role = $user->getRelationValue('role');
        if (! $role instanceof Role) {
            return false;
        }

        $userTenantId = $user->tenant_id !== null ? (int) $user->tenant_id : null;
        $roleTenantId = $role->tenant_id !== null ? (int) $role->tenant_id : null;

        return $userTenantId === null && $roleTenantId === null && (string) $role->status === '1';
    }

    private function headerTenantId(Request $request): int
    {
        $headerTenantId = $request->header('X-Tenant-Id');
        $headerValue = is_string($headerTenantId) ? trim($headerTenantId) : '';

        return is_numeric($headerValue) ? (int) $headerValue : 0;
    }
}

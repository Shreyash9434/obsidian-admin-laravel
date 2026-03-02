<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Access\Models\Role;
use App\Domains\Access\Models\User;
use App\Domains\Access\Models\UserPreference;
use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Team;
use App\Domains\Tenant\Models\Tenant;
use Database\Seeders\Support\SeedCatalog;
use Database\Seeders\Support\VersionedSeeder;
use RuntimeException;

class UserSeeder extends VersionedSeeder
{
    /**
     * @return list<string>
     */
    protected function requiredTables(): array
    {
        return array_merge(parent::requiredTables(), [
            'users',
            'roles',
            'tenants',
            'organizations',
            'teams',
            'user_preferences',
        ]);
    }

    protected function module(): string
    {
        return 'identity.users';
    }

    /**
     * @return array<int, list<array{
     *   name: string,
     *   email: string,
     *   password: string,
     *   status: string,
     *   roleCode: string,
     *   tenantCode: string|null,
     *   organizationCode: string|null,
     *   teamCode: string|null
     * }>>
     */
    protected function versionedPayloads(): array
    {
        return [
            1 => SeedCatalog::users(),
        ];
    }

    protected function applyVersion(int $version, mixed $payload): void
    {
        unset($version);

        /** @var list<array{
         *   name: string,
         *   email: string,
         *   password: string,
         *   status: string,
         *   roleCode: string,
         *   tenantCode: string|null,
         *   organizationCode: string|null,
         *   teamCode: string|null
         * }> $seedUsers
         */
        $seedUsers = $payload;

        $tenantIdsByCode = Tenant::query()
            ->pluck('id', 'code')
            ->mapWithKeys(static fn (mixed $id, mixed $code): array => [(string) $code => (int) $id])
            ->all();

        $roleIdsByScopeCode = Role::query()
            ->select(['id', 'code', 'tenant_scope_id'])
            ->get()
            ->mapWithKeys(static fn (Role $role): array => [
                sprintf('%d:%s', (int) $role->tenant_scope_id, (string) $role->code) => (int) $role->id,
            ])
            ->all();

        $organizationIdsByTenantAndCode = Organization::query()
            ->select(['id', 'tenant_id', 'code'])
            ->get()
            ->mapWithKeys(static fn (Organization $organization): array => [
                sprintf('%d:%s', (int) $organization->tenant_id, (string) $organization->code) => (int) $organization->id,
            ])
            ->all();

        $teamRowsByTenantAndCode = Team::query()
            ->select(['id', 'tenant_id', 'organization_id', 'code'])
            ->get()
            ->mapWithKeys(static fn (Team $team): array => [
                sprintf('%d:%s', (int) $team->tenant_id, (string) $team->code) => [
                    'id' => (int) $team->id,
                    'organizationId' => (int) $team->organization_id,
                ],
            ])
            ->all();

        foreach ($seedUsers as $seedUser) {
            $tenantCode = $seedUser['tenantCode'];
            $tenantId = null;

            if ($tenantCode !== null) {
                $tenantId = $tenantIdsByCode[$tenantCode] ?? null;
                if (! is_int($tenantId) || $tenantId <= 0) {
                    throw new RuntimeException("Seed tenant not found for user [{$seedUser['email']}]");
                }
            }

            $scopeId = $tenantId ?? 0;
            $roleKey = sprintf('%d:%s', $scopeId, $seedUser['roleCode']);
            $roleId = $roleIdsByScopeCode[$roleKey] ?? null;

            if (! is_int($roleId) || $roleId <= 0) {
                throw new RuntimeException("Seed role not found for user [{$seedUser['email']}] with scope [{$scopeId}]");
            }

            $organizationId = null;
            $organizationCode = $seedUser['organizationCode'] ?? null;
            if ($organizationCode !== null) {
                if (! is_int($tenantId) || $tenantId <= 0) {
                    throw new RuntimeException("Seed organization scope requires tenant for user [{$seedUser['email']}]");
                }

                $organizationKey = sprintf('%d:%s', $tenantId, $organizationCode);
                $organizationId = $organizationIdsByTenantAndCode[$organizationKey] ?? null;
                if (! is_int($organizationId) || $organizationId <= 0) {
                    throw new RuntimeException("Seed organization not found for user [{$seedUser['email']}] with key [{$organizationKey}]");
                }
            }

            $teamId = null;
            $teamCode = $seedUser['teamCode'] ?? null;
            if ($teamCode !== null) {
                if (! is_int($tenantId) || $tenantId <= 0) {
                    throw new RuntimeException("Seed team scope requires tenant for user [{$seedUser['email']}]");
                }

                $teamKey = sprintf('%d:%s', $tenantId, $teamCode);
                $teamRow = $teamRowsByTenantAndCode[$teamKey] ?? null;
                if (! is_array($teamRow)) {
                    throw new RuntimeException("Seed team not found for user [{$seedUser['email']}] with key [{$teamKey}]");
                }

                $teamId = (int) ($teamRow['id'] ?? 0);
                $teamOrganizationId = (int) ($teamRow['organizationId'] ?? 0);
                if ($teamId <= 0 || $teamOrganizationId <= 0) {
                    throw new RuntimeException("Seed team mapping is invalid for user [{$seedUser['email']}] with key [{$teamKey}]");
                }

                if ($organizationId !== null && $teamOrganizationId !== $organizationId) {
                    throw new RuntimeException("Seed team organization mismatch for user [{$seedUser['email']}]");
                }

                $organizationId = $organizationId ?? $teamOrganizationId;
            }

            $user = User::query()->withTrashed()->updateOrCreate(
                ['email' => $seedUser['email']],
                [
                    'name' => $seedUser['name'],
                    'password' => $seedUser['password'],
                    'status' => $seedUser['status'],
                    'role_id' => $roleId,
                    'tenant_id' => $tenantId,
                    'organization_id' => $organizationId,
                    'team_id' => $teamId,
                    'tenant_scope_id' => $scopeId,
                    'deleted_at' => null,
                ]
            );

            UserPreference::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'locale' => SeedCatalog::defaultLocale(),
                    'timezone' => SeedCatalog::DEFAULT_TIMEZONE,
                ]
            );
        }

        $this->assertUserRoleTenantIntegrity();
    }

    private function assertUserRoleTenantIntegrity(): void
    {
        $invalidUsers = User::query()
            ->with('role:id,tenant_id', 'organization:id,tenant_id', 'team:id,tenant_id,organization_id')
            ->get()
            ->filter(static function (User $user): bool {
                $role = $user->role;
                if (! $role instanceof Role) {
                    return true;
                }

                $userTenantId = $user->tenant_id;
                $roleTenantId = $role->tenant_id;

                if ($userTenantId === null) {
                    return $roleTenantId !== null;
                }

                if ((int) $roleTenantId !== (int) $userTenantId) {
                    return true;
                }

                $organization = $user->organization;
                if ($organization instanceof Organization && (int) $organization->tenant_id !== (int) $userTenantId) {
                    return true;
                }

                $team = $user->team;
                if (! $team instanceof Team) {
                    return false;
                }

                if ((int) $team->tenant_id !== (int) $userTenantId) {
                    return true;
                }

                if ($organization instanceof Organization && (int) $team->organization_id !== (int) $organization->id) {
                    return true;
                }

                return false;
            })
            ->map(static function (User $user): string {
                $roleTenantId = $user->role instanceof Role ? $user->role->tenant_id : null;
                $organizationTenantId = $user->organization instanceof Organization ? $user->organization->tenant_id : null;
                $teamTenantId = $user->team instanceof Team ? $user->team->tenant_id : null;

                return sprintf(
                    '%s(email=%s,user_tenant=%s,role_tenant=%s,organization_tenant=%s,team_tenant=%s)',
                    $user->name,
                    $user->email,
                    $user->tenant_id === null ? 'null' : (string) $user->tenant_id,
                    $roleTenantId === null ? 'null' : (string) $roleTenantId,
                    $organizationTenantId === null ? 'null' : (string) $organizationTenantId,
                    $teamTenantId === null ? 'null' : (string) $teamTenantId
                );
            })
            ->values()
            ->all();

        if ($invalidUsers !== []) {
            throw new RuntimeException(
                'Seeded users have invalid tenant-role scope: '.implode('; ', $invalidUsers)
            );
        }
    }
}

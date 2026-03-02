<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Team;
use App\Domains\Tenant\Models\Tenant;
use Database\Seeders\Support\SeedCatalog;
use Database\Seeders\Support\VersionedSeeder;
use RuntimeException;

class TeamSeeder extends VersionedSeeder
{
    /**
     * @return list<string>
     */
    protected function requiredTables(): array
    {
        return array_merge(parent::requiredTables(), ['teams', 'organizations', 'tenants']);
    }

    protected function module(): string
    {
        return 'tenant.teams';
    }

    /**
     * @return array<int, list<array{
     *   tenantCode: string,
     *   organizationCode: string,
     *   code: string,
     *   name: string,
     *   status: string,
     *   sort: int
     * }>>
     */
    protected function versionedPayloads(): array
    {
        return [
            1 => SeedCatalog::teams(),
        ];
    }

    protected function applyVersion(int $version, mixed $payload): void
    {
        unset($version);

        /** @var list<array{
         *   tenantCode: string,
         *   organizationCode: string,
         *   code: string,
         *   name: string,
         *   status: string,
         *   sort: int
         * }> $teams
         */
        $teams = $payload;

        $tenantIdsByCode = Tenant::query()
            ->pluck('id', 'code')
            ->mapWithKeys(static fn (mixed $id, mixed $code): array => [(string) $code => (int) $id])
            ->all();

        $organizationIdsByTenantAndCode = Organization::query()
            ->select(['id', 'tenant_id', 'code'])
            ->get()
            ->mapWithKeys(static fn (Organization $organization): array => [
                sprintf('%d:%s', (int) $organization->tenant_id, (string) $organization->code) => (int) $organization->id,
            ])
            ->all();

        foreach ($teams as $teamData) {
            $tenantId = $tenantIdsByCode[$teamData['tenantCode']] ?? null;
            if (! is_int($tenantId) || $tenantId <= 0) {
                throw new RuntimeException(sprintf(
                    'Seed tenant not found for team [%s] with tenant code [%s]',
                    $teamData['code'],
                    $teamData['tenantCode']
                ));
            }

            $organizationKey = sprintf('%d:%s', $tenantId, $teamData['organizationCode']);
            $organizationId = $organizationIdsByTenantAndCode[$organizationKey] ?? null;

            if (! is_int($organizationId) || $organizationId <= 0) {
                throw new RuntimeException(sprintf(
                    'Seed organization not found for team [%s] with organization code [%s] in tenant [%s]',
                    $teamData['code'],
                    $teamData['organizationCode'],
                    $teamData['tenantCode']
                ));
            }

            Team::query()->withTrashed()->updateOrCreate(
                [
                    'organization_id' => $organizationId,
                    'code' => $teamData['code'],
                ],
                [
                    'tenant_id' => $tenantId,
                    'name' => $teamData['name'],
                    'status' => $teamData['status'],
                    'sort' => (int) $teamData['sort'],
                    'description' => (string) ($teamData['description'] ?? ''),
                    'deleted_at' => null,
                ]
            );
        }
    }
}

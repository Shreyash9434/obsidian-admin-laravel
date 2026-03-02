<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Tenant\Models\Organization;
use App\Domains\Tenant\Models\Tenant;
use Database\Seeders\Support\SeedCatalog;
use Database\Seeders\Support\VersionedSeeder;
use RuntimeException;

class OrganizationSeeder extends VersionedSeeder
{
    /**
     * @return list<string>
     */
    protected function requiredTables(): array
    {
        return array_merge(parent::requiredTables(), ['organizations', 'tenants']);
    }

    protected function module(): string
    {
        return 'tenant.organizations';
    }

    /**
     * @return array<int, list<array{
     *   tenantCode: string,
     *   code: string,
     *   name: string,
     *   status: string,
     *   sort: int
     * }>>
     */
    protected function versionedPayloads(): array
    {
        return [
            1 => SeedCatalog::organizations(),
        ];
    }

    protected function applyVersion(int $version, mixed $payload): void
    {
        unset($version);

        /** @var list<array{
         *   tenantCode: string,
         *   code: string,
         *   name: string,
         *   status: string,
         *   sort: int
         * }> $organizations
         */
        $organizations = $payload;

        $tenantIdsByCode = Tenant::query()
            ->pluck('id', 'code')
            ->mapWithKeys(static fn (mixed $id, mixed $code): array => [(string) $code => (int) $id])
            ->all();

        foreach ($organizations as $organizationData) {
            $tenantId = $tenantIdsByCode[$organizationData['tenantCode']] ?? null;
            if (! is_int($tenantId) || $tenantId <= 0) {
                throw new RuntimeException(sprintf(
                    'Seed tenant not found for organization [%s] with tenant code [%s]',
                    $organizationData['code'],
                    $organizationData['tenantCode']
                ));
            }

            Organization::query()->withTrashed()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'code' => $organizationData['code'],
                ],
                [
                    'name' => $organizationData['name'],
                    'status' => $organizationData['status'],
                    'sort' => (int) $organizationData['sort'],
                    'description' => (string) ($organizationData['description'] ?? ''),
                    'deleted_at' => null,
                ]
            );
        }
    }
}

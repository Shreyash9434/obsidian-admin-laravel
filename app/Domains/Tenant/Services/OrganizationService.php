<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Services;

use App\Domains\Shared\Services\ApiCacheService;
use App\Domains\Tenant\Models\Organization;
use App\DTOs\Organization\CreateOrganizationDTO;
use App\DTOs\Organization\UpdateOrganizationDTO;

class OrganizationService
{
    public function __construct(private readonly ApiCacheService $apiCacheService) {}

    public function create(int $tenantId, CreateOrganizationDTO $dto): Organization
    {
        $organization = Organization::query()->create([
            'tenant_id' => $tenantId,
            'code' => $dto->organizationCode,
            'name' => $dto->organizationName,
            'description' => $dto->description,
            'status' => $dto->status,
            'sort' => $dto->sort,
        ]);

        $this->apiCacheService->bump('organizations');

        return $organization;
    }

    public function update(Organization $organization, UpdateOrganizationDTO $dto): Organization
    {
        $organization->forceFill([
            'code' => $dto->organizationCode,
            'name' => $dto->organizationName,
            'description' => $dto->description,
            'status' => $dto->status ?? (string) $organization->status,
            'sort' => $dto->sort ?? (int) ($organization->sort ?? 0),
        ])->save();

        $this->apiCacheService->bump('organizations');

        return $organization;
    }

    public function delete(Organization $organization): void
    {
        $organization->delete();
        $this->apiCacheService->bump('organizations');
    }
}

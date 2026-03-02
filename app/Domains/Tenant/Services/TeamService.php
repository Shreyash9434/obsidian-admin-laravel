<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Services;

use App\Domains\Shared\Services\ApiCacheService;
use App\Domains\Tenant\Models\Team;
use App\DTOs\Team\CreateTeamDTO;
use App\DTOs\Team\UpdateTeamDTO;

class TeamService
{
    public function __construct(private readonly ApiCacheService $apiCacheService) {}

    public function create(int $tenantId, CreateTeamDTO $dto): Team
    {
        $team = Team::query()->create([
            'tenant_id' => $tenantId,
            'organization_id' => $dto->organizationId,
            'code' => $dto->teamCode,
            'name' => $dto->teamName,
            'description' => $dto->description,
            'status' => $dto->status,
            'sort' => $dto->sort,
        ]);

        $this->apiCacheService->bump('teams');

        return $team;
    }

    public function update(Team $team, UpdateTeamDTO $dto): Team
    {
        $team->forceFill([
            'organization_id' => $dto->organizationId,
            'code' => $dto->teamCode,
            'name' => $dto->teamName,
            'description' => $dto->description,
            'status' => $dto->status ?? (string) $team->status,
            'sort' => $dto->sort ?? (int) ($team->sort ?? 0),
        ])->save();

        $this->apiCacheService->bump('teams');

        return $team;
    }

    public function delete(Team $team): void
    {
        $team->delete();
        $this->apiCacheService->bump('teams');
    }
}

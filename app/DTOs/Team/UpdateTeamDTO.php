<?php

declare(strict_types=1);

namespace App\DTOs\Team;

readonly class UpdateTeamDTO
{
    public function __construct(
        public int $organizationId,
        public string $teamCode,
        public string $teamName,
        public string $description,
        public ?string $status,
        public ?int $sort,
    ) {}
}

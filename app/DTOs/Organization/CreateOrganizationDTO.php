<?php

declare(strict_types=1);

namespace App\DTOs\Organization;

readonly class CreateOrganizationDTO
{
    public function __construct(
        public string $organizationCode,
        public string $organizationName,
        public string $description,
        public string $status,
        public int $sort,
    ) {}
}

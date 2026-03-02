<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Organization;

use App\DTOs\Organization\CreateOrganizationDTO;
use App\Http\Requests\Api\BaseApiRequest;

class StoreOrganizationRequest extends BaseApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'organizationCode' => ['required', 'string', 'max:64'],
            'organizationName' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'in:1,2'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function toDTO(): CreateOrganizationDTO
    {
        $validated = $this->validated();

        return new CreateOrganizationDTO(
            organizationCode: trim((string) $validated['organizationCode']),
            organizationName: trim((string) $validated['organizationName']),
            description: trim((string) ($validated['description'] ?? '')),
            status: (string) ($validated['status'] ?? '1'),
            sort: (int) ($validated['sort'] ?? 0),
        );
    }
}

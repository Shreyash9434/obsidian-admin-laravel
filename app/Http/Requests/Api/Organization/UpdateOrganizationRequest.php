<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Organization;

use App\DTOs\Organization\UpdateOrganizationDTO;
use App\Http\Requests\Api\BaseApiRequest;

class UpdateOrganizationRequest extends BaseApiRequest
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
            'version' => ['nullable', 'integer', 'min:1'],
            'updatedAt' => ['nullable', 'string', 'max:64'],
            'updateTime' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function toDTO(): UpdateOrganizationDTO
    {
        $validated = $this->validated();

        return new UpdateOrganizationDTO(
            organizationCode: trim((string) $validated['organizationCode']),
            organizationName: trim((string) $validated['organizationName']),
            description: trim((string) ($validated['description'] ?? '')),
            status: array_key_exists('status', $validated) ? (string) $validated['status'] : null,
            sort: array_key_exists('sort', $validated) ? (int) $validated['sort'] : null,
        );
    }
}

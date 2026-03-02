<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Team;

use App\DTOs\Team\UpdateTeamDTO;
use App\Http\Requests\Api\BaseApiRequest;

class UpdateTeamRequest extends BaseApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'organizationId' => ['required', 'integer', 'min:1'],
            'teamCode' => ['required', 'string', 'max:64'],
            'teamName' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'in:1,2'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'version' => ['nullable', 'integer', 'min:1'],
            'updatedAt' => ['nullable', 'string', 'max:64'],
            'updateTime' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function toDTO(): UpdateTeamDTO
    {
        $validated = $this->validated();

        return new UpdateTeamDTO(
            organizationId: (int) $validated['organizationId'],
            teamCode: trim((string) $validated['teamCode']),
            teamName: trim((string) $validated['teamName']),
            description: trim((string) ($validated['description'] ?? '')),
            status: array_key_exists('status', $validated) ? (string) $validated['status'] : null,
            sort: array_key_exists('sort', $validated) ? (int) $validated['sort'] : null,
        );
    }
}

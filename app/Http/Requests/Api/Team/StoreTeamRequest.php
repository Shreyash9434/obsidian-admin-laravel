<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Team;

use App\DTOs\Team\CreateTeamDTO;
use App\Http\Requests\Api\BaseApiRequest;

class StoreTeamRequest extends BaseApiRequest
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
        ];
    }

    public function toDTO(): CreateTeamDTO
    {
        $validated = $this->validated();

        return new CreateTeamDTO(
            organizationId: (int) $validated['organizationId'],
            teamCode: trim((string) $validated['teamCode']),
            teamName: trim((string) $validated['teamName']),
            description: trim((string) ($validated['description'] ?? '')),
            status: (string) ($validated['status'] ?? '1'),
            sort: (int) ($validated['sort'] ?? 0),
        );
    }
}

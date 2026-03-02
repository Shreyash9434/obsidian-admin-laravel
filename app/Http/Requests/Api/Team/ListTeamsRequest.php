<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Team;

use App\Http\Requests\Api\BaseApiRequest;

class ListTeamsRequest extends BaseApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'current' => ['nullable', 'integer', 'min:1'],
            'size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:255'],
            'keyword' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:1,2'],
            'organizationId' => ['nullable', 'integer', 'min:1'],
        ];
    }
}

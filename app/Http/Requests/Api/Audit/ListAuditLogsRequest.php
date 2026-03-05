<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Audit;

use App\Http\Requests\Api\BaseApiRequest;

class ListAuditLogsRequest extends BaseApiRequest
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
            'action' => ['nullable', 'string', 'max:120'],
            'logType' => ['nullable', 'string', 'in:login,api,operation,data,permission'],
            'userName' => ['nullable', 'string', 'max:120'],
            'dateFrom' => ['nullable', 'date'],
            'dateTo' => ['nullable', 'date'],
        ];
    }
}

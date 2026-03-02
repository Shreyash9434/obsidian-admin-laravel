<?php

declare(strict_types=1);

namespace App\Domains\Tenant\Http\Resources;

use App\Domains\Tenant\Models\Team;
use App\Support\ApiDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Team */
class TeamListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $organizationName = $this->organization?->getAttribute('name');

        return [
            'id' => $this->id,
            'tenantId' => $this->tenant_id ? (string) $this->tenant_id : '',
            'organizationId' => $this->organization_id ? (string) $this->organization_id : '',
            'organizationName' => is_string($organizationName) ? $organizationName : '',
            'teamCode' => (string) $this->code,
            'teamName' => (string) $this->name,
            'description' => (string) ($this->description ?? ''),
            'status' => (string) $this->status,
            'sort' => (int) ($this->sort ?? 0),
            'userCount' => (int) ($this->users_count ?? 0),
            'version' => (string) ($this->updated_at?->copy()->setTimezone('UTC')->timestamp ?? 0),
            'createTime' => ApiDateTime::formatForRequest($this->created_at, $request),
            'updateTime' => ApiDateTime::formatForRequest($this->updated_at, $request),
        ];
    }
}

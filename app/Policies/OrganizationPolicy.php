<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Access\Models\User;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('organization.view') || $user->hasPermission('organization.manage');
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission('organization.manage');
    }
}

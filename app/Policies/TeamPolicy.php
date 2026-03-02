<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domains\Access\Models\User;

class TeamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('team.view') || $user->hasPermission('team.manage');
    }

    public function manage(User $user): bool
    {
        return $user->hasPermission('team.manage');
    }
}

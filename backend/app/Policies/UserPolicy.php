<?php

namespace App\Policies;

use App\Models\User;

/**
 * Decides who may moderate a user account.
 */
class UserPolicy
{
    /**
     * Only administrators may ban or unban accounts.
     */
    public function ban(User $user, User $target): bool
    {
        return $user->role->isAdmin() && $user->id !== $target->id;
    }

    /**
     * Only administrators may change roles.
     */
    public function changeRole(User $user, User $target): bool
    {
        return $user->role->isAdmin() && $user->id !== $target->id;
    }

    /**
     * Moderators and administrators may review reports.
     */
    public function reviewReports(User $user): bool
    {
        return $user->role->isModerator();
    }

    /**
     * A user may block anyone except themselves.
     */
    public function block(User $user, User $target): bool
    {
        return $user->id !== $target->id;
    }

    /**
     * A user may report anyone except themselves.
     */
    public function report(User $user, User $target): bool
    {
        return $user->id !== $target->id;
    }

    /**
     * Administrators may view the administration panel.
     */
    public function accessAdminPanel(User $user): bool
    {
        return $user->role->isModerator();
    }

    /**
     * Only administrators may delete another user's account.
     */
    public function delete(User $user, User $target): bool
    {
        return $user->role->isAdmin() && $user->id !== $target->id;
    }
}

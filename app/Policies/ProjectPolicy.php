<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/** Owners and admins have full access; members get viewer or editor access per project. */
class ProjectPolicy
{
    private function memberRole(User $user, Project $project): ?string
    {
        return $project->members()->where('users.id', $user->id)->first()?->pivot?->role;
    }

    public function view(User $user, Project $project): bool
    {
        return $project->user_id === $user->id || $user->isAdmin() || $this->memberRole($user, $project) !== null;
    }

    public function update(User $user, Project $project): bool
    {
        return $project->user_id === $user->id || $user->isAdmin() || $this->memberRole($user, $project) === 'editor';
    }

    public function produce(User $user, Project $project): bool
    {
        return $this->update($user, $project) && $user->hasPermission('render.start');
    }

    public function approve(User $user, Project $project): bool
    {
        return ($project->user_id === $user->id || $user->isAdmin()) && $user->hasPermission('packages.approve');
    }

    public function download(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $project->user_id === $user->id || $user->isAdmin();
    }
}

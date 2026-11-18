<?php

namespace App\Contracts;

use App\Models\Project;
use App\Models\User;
use App\Services\Production\Data\PlanResult;

interface ScenePlannerInterface
{
    /** Produce a validated scene plan for the project's approved package. */
    public function plan(Project $project, User $user, array $options = []): PlanResult;
}

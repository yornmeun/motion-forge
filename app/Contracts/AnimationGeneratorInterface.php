<?php

namespace App\Contracts;

use App\Models\Scene;
use App\Models\User;
use App\Services\Production\Data\CodeResult;

interface AnimationGeneratorInterface
{
    /** Generate Canvas 2D scene code (`function scene(ctx, t) {...}`) for one planned scene. */
    public function generate(Scene $scene, array $plan, User $user, array $options = []): CodeResult;
}

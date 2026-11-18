<?php

namespace App\Services\Rendering;

use RuntimeException;

class SceneRenderException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $sceneIndex = null)
    {
        parent::__construct($message);
    }
}

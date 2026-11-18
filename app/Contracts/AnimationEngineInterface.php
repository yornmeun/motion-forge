<?php

namespace App\Contracts;

use App\Models\Project;
use App\Models\Scene;

/** Stable adapter around the Kikitoons (or compatible) engine. All rendering runs out of process. */
interface AnimationEngineInterface
{
    /** Static validation of untrusted scene code. @return array{ok: bool, errors: array, warnings: array} */
    public function validateCode(string $code): array;

    /** Render one test frame of a scene with candidate code. @return array{ok: bool, message: ?string} */
    public function probeCode(Scene $scene, string $code): array;

    /** Render one still of a scene (sandboxed). Returns the storage-relative PNG path. */
    public function generatePreview(Scene $scene, ?float $at = null): string;

    /** Render one scene to a silent MP4. Returns the storage-relative path. */
    public function renderScene(Scene $scene, ?callable $onProgress = null): string;

    /** Probe every scene for runtime errors without rendering video. @return array{ok: bool, scene: ?int, message: ?string} */
    public function probeProject(Project $project): array;

    public function engineInfo(): array;
}

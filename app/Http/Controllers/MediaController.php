<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectFile;
use App\Models\RenderArtifact;
use App\Models\SceneAsset;
use App\Models\Thumbnail;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Authorised streaming of private project media (previews, clips, voice, renders). Supports range requests. */
class MediaController extends Controller
{
    public function show(Project $project, string $kind, string $id): BinaryFileResponse
    {
        Gate::authorize('view', $project);
        $path = match ($kind) {
            'scene-asset' => SceneAsset::whereKey($id)->whereHas('scene', fn ($q) => $q->where('project_id', $project->id))->value('path'),
            'file' => ProjectFile::whereKey($id)->where('project_id', $project->id)->value('path'),
            'artifact' => RenderArtifact::whereKey($id)->where('project_id', $project->id)->value('path'),
            'thumbnail' => Thumbnail::whereKey($id)->where('project_id', $project->id)->value('path'),
            'thumb-check' => (fn () => ($t = Thumbnail::where('project_id', $project->id)->latest()->first()) ? (match (request('v')) {
                'crop' => $t->checks['crop_3x4'] ?? null, 'medium' => $t->checks['640x360'] ?? null, default => $t->checks['small'] ?? $t->checks['168x94'] ?? null
            }) : null)(),
            default => null,
        };
        $disk = Storage::disk(config('motionforge.disk'));
        abort_unless($path && str_starts_with($path, $project->storageDir()) && ! str_contains($path, '..') && $disk->exists($path), 404);

        return response()->file($disk->path($path), ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}

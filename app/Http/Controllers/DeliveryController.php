<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\RenderArtifact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliveryController extends Controller
{
    public function index(Request $request): View
    {
        $ids = $request->user()->projects()->pluck('id');

        return view('delivery.index', ['videos' => RenderArtifact::with('project')->whereIn('project_id', $ids)->where('type', 'final_video')->where('is_delivery', true)->latest()->paginate(12)]);
    }

    public function download(Project $project, string $type): StreamedResponse
    {
        Gate::authorize('download', $project);
        $slug = Str::slug($project->title) ?: 'motionforge';
        [$path, $name] = match ($type) {
            'video' => [$project->finalVideo()?->path, 'final.mp4'],
            'thumbnail' => [$project->thumbnails()->first()?->path, 'thumbnail.png'],
            'srt' => [$project->artifacts()->where('type', 'srt_final')->latest()->first()?->path, $slug.'.srt'],
        };
        $disk = Storage::disk(config('motionforge.disk'));
        abort_unless($path && str_starts_with($path, $project->storageDir()) && $disk->exists($path), 404);
        $project->log('delivery.download', "Downloaded {$name}.");

        return $disk->download($path, $type === 'srt' ? $name : $slug.'-'.$name);
    }
}

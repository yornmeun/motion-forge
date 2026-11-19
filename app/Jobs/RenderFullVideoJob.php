<?php

namespace App\Jobs;

use App\Jobs\Concerns\RenderPipelineStep;
use App\Models\Project;
use App\Models\RenderArtifact;
use App\Models\RenderJob;
use App\Services\Rendering\KikitoonsEngine;
use App\Services\Rendering\VideoAssembler;
use RuntimeException;

/** FFmpeg assembly of the rendered scene segments + mix into final.mp4 (H.264 / AAC). */
class RenderFullVideoJob extends RenderPipelineStep
{
    protected function stageName(): string
    {
        return 'FFmpeg video assembly';
    }

    protected function run(RenderJob $rj, Project $project): void
    {
        $engine = app(KikitoonsEngine::class);
        $assembler = app(VideoAssembler::class);
        $disk = $this->disk();
        $work = $engine->tmp('assemble');
        try {
            $segments = [];
            foreach ($project->scenes()->with('currentVersion', 'project')->get() as $scene) {
                $rel = RenderSceneJob::segmentFor($scene) ?? throw new RuntimeException("Scene {$scene->sequence} is missing a current render. Retry that scene.");
                $segments[] = $disk->path($rel);
            }
            $silent = $work.'/video.mp4';
            $assembler->concat($segments, $work, $silent);
            $ass = null;
            if ($project->production_mode->value !== 'kikitoons') {
                $capsRel = $project->storageDir('renders/caps.json');
                if ($disk->exists($capsRel)) {
                    $ass = $work.'/subs.ass';
                    file_put_contents($ass, $assembler->ass(json_decode($disk->get($capsRel), true), $project->width, $project->height, $project->captionY()));
                }
            }
            $finalRel = $project->storageDir('delivery/final.mp4');
            $disk->makeDirectory(dirname($finalRel));
            $assembler->mux($silent, $disk->path($project->storageDir('renders/mix.wav')), $disk->path($finalRel), $ass, $project->frame_rate);
            RenderArtifact::where('project_id', $project->id)->where('type', 'final_video')->update(['is_delivery' => false]);
            RenderArtifact::create(['render_job_id' => $rj->id, 'project_id' => $project->id, 'type' => 'final_video', 'path' => $finalRel, 'size' => $disk->size($finalRel), 'sha256' => hash_file('sha256', $disk->path($finalRel)), 'is_delivery' => true, 'metadata' => ['scenes' => count($segments), 'engine' => $engine->engineInfo()['kind']]]);
            $rj->appendLog('final.mp4 assembled from '.count($segments).' scene segments.');
        } finally {
            $engine->cleanup($work);
        }
    }
}

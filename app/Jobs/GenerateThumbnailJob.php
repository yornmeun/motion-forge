<?php

namespace App\Jobs;

use App\Jobs\Concerns\RenderPipelineStep;
use App\Models\Project;
use App\Models\RenderArtifact;
use App\Models\RenderJob;
use App\Services\Production\CaptionWriter;
use App\Services\Rendering\ThumbnailService;

class GenerateThumbnailJob extends RenderPipelineStep
{
    protected function stageName(): string { return 'Designing thumbnail'; }

    protected function run(RenderJob $rj, Project $project): void
    {
        $t = cache()->get('thumb-title:'.$project->id) ?? app(CaptionWriter::class)->template($project);
        $title = $project->setting('thumbnail.title') ?: $t['title'];
        $thumb = app(ThumbnailService::class)->make($project, $title, $t['keyword'] ?? null);
        $art = RenderArtifact::create(['render_job_id' => $rj->id, 'project_id' => $project->id, 'type' => 'thumbnail', 'path' => $thumb->path, 'size' => $this->disk()->size($thumb->path), 'is_delivery' => true]);
        $thumb->update(['render_artifact_id' => $art->id]);
        $rj->appendLog('thumbnail.png rendered: “'.$thumb->title_text.'”');
    }
}

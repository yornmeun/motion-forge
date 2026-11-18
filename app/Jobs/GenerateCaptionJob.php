<?php

namespace App\Jobs;

use App\Jobs\Concerns\RenderPipelineStep;
use App\Models\Project;
use App\Models\RenderJob;
use App\Services\Production\CaptionWriter;

class GenerateCaptionJob extends RenderPipelineStep
{
    protected function stageName(): string { return 'Writing post caption'; }

    protected function run(RenderJob $rj, Project $project): void
    {
        $writer = app(CaptionWriter::class);
        $c = $writer->write($project, $rj->project->user);
        $writer->store($project, $c);
        cache()->put('thumb-title:'.$project->id, ['title' => $c['title'], 'keyword' => $c['keyword']], now()->addDay());
        $rj->appendLog("Caption by {$c['origin']}; thumbnail title “{$c['title']}”.");
    }
}

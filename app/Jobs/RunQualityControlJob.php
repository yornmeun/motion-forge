<?php

namespace App\Jobs;

use App\Enums\ProductionStatus;
use App\Jobs\Concerns\RenderPipelineStep;
use App\Models\Project;
use App\Models\RenderJob;
use App\Services\Rendering\QualityControl;

class RunQualityControlJob extends RenderPipelineStep
{
    protected function stageName(): string { return 'Technical quality control'; }

    protected function run(RenderJob $rj, Project $project): void
    {
        $project->update(['status' => ProductionStatus::QualityControl]);
        $final = $project->finalVideo() ?? throw new \RuntimeException('No final video to check.');
        $disk = $this->disk();
        $capsRel = $project->storageDir('renders/caps.json');
        $caps = $disk->exists($capsRel) ? json_decode($disk->get($capsRel), true) : [];
        $qc = app(QualityControl::class);
        $results = $qc->run($project, $final, (float) $project->scenes()->max('end_time'), $caps ?: []);
        $thumb = $project->thumbnails()->first();
        $qc->checkThumbnail($project, $thumb ? $disk->path($thumb->path) : null);
        $qc->seedCreativeChecklist($project);
        $fails = collect($results)->where('status', 'fail')->count();
        $rj->appendLog("QC: {$fails} failed, ".collect($results)->where('status', 'warn')->count().' warnings.');
    }
}

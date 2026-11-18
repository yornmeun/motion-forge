<?php

namespace App\Jobs;

use App\Enums\JobStatus;
use App\Enums\ProductionStatus;
use App\Jobs\Concerns\RenderPipelineStep;
use App\Models\Project;
use App\Models\RenderJob;
use App\Notifications\ProductionCompleted;
use App\Services\Rendering\KikitoonsEngine;

class FinalizeProductionJob extends RenderPipelineStep
{
    protected function stageName(): string { return 'Finalizing delivery'; }

    protected function run(RenderJob $rj, Project $project): void
    {
        $fails = $project->qualityChecks()->where('kind', 'technical')->where('status', 'fail')->count();
        $project->update(['status' => $fails ? ProductionStatus::QualityControl : ProductionStatus::Completed]);
        $rj->update(['status' => JobStatus::Succeeded, 'stage' => $fails ? "Rendered — {$fails} QC check(s) failed" : 'Completed', 'finished_at' => now()]);
        $project->log('render.completed', $fails ? "Render finished with {$fails} failed QC check(s)." : 'Final video, thumbnail and caption delivered.');
        // keep only current scene segments; remove stale renders and temp files
        $keep = $project->scenes()->with('currentVersion', 'project')->get()->map(fn ($s) => RenderSceneJob::segmentFor($s))->filter()->all();
        foreach ($this->disk()->files($project->storageDir('renders/scenes')) as $f) {
            if (! in_array($f, $keep, true)) $this->disk()->delete($f);
        }
        try { $project->user?->notify(new ProductionCompleted($project, $fails === 0)); } catch (\Throwable) {}
    }
}

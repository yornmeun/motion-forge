<?php

namespace App\Jobs\Concerns;

use App\Enums\JobStatus;
use App\Enums\ProductionStatus;
use App\Models\Project;
use App\Models\RenderJob;
use App\Notifications\ProductionFailed;
use App\Models\GenerationJob;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** A stage of the full-render pipeline, reporting into one render_jobs row. */
abstract class RenderPipelineStep implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 3600;

    public function __construct(public string $renderJobId) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    abstract protected function stageName(): string;

    abstract protected function run(RenderJob $rj, Project $project): void;

    public function handle(): void
    {
        $rj = RenderJob::with('project')->findOrFail($this->renderJobId);
        if ($rj->cancel_requested_at || in_array($rj->status, [JobStatus::Cancelled, JobStatus::Failed], true)) {
            if ($rj->status !== JobStatus::Failed) $rj->update(['status' => JobStatus::Cancelled, 'stage' => 'Cancelled', 'finished_at' => now()]);

            return;
        }
        $rj->update(['status' => JobStatus::Running, 'stage' => $this->stageName(), 'started_at' => $rj->started_at ?? now()]);
        $rj->appendLog($this->stageName());
        $this->run($rj, $rj->project);
    }

    public function failed(?Throwable $e): void
    {
        $rj = RenderJob::with('project')->find($this->renderJobId);
        if (! $rj) return;
        $rj->update(['status' => JobStatus::Failed, 'stage' => 'Failed: '.$this->stageName(), 'error' => mb_substr((string) $e?->getMessage(), 0, 2000), 'finished_at' => now()]);
        $rj->appendLog('FAILED: '.mb_substr((string) $e?->getMessage(), 0, 500));
        $rj->project->update(['status' => ProductionStatus::Failed]);
        $rj->project->log('render.failed', $this->stageName().' failed: '.mb_substr((string) $e?->getMessage(), 0, 300));
        $proxy = new GenerationJob(['project_id' => $rj->project_id, 'type' => 'render', 'error' => $e?->getMessage()]);
        $proxy->setRelation('project', $rj->project);
        try { $rj->project->user?->notify(new ProductionFailed($proxy)); } catch (Throwable) {}
    }

    protected function disk()
    {
        return \Storage::disk(config('motionforge.disk'));
    }
}

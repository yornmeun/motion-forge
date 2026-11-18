<?php

namespace App\Jobs;

use App\Enums\ProductionStatus;
use App\Jobs\Concerns\TracksGenerationJob;
use App\Models\Project;
use App\Models\User;
use App\Services\Production\AIStoryboardPlanner;
use App\Services\Production\ProductionWorkflow;
use App\Services\Production\StoryboardService;
use App\Services\Production\TemplateStoryboardPlanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateStoryboardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksGenerationJob;

    public int $tries = 3;
    public int $timeout = 1800;

    public function __construct(public string $projectId, public int $userId, public ?string $generationJobId = null, public array $options = []) {}

    public function handle(StoryboardService $boards, ProductionWorkflow $workflow): void
    {
        if (! $this->begin('Planning scenes from the approved package')) return;
        $project = Project::findOrFail($this->projectId);
        $user = User::findOrFail($this->userId);
        try {
            $project->update(['status' => ProductionStatus::Planning]);
            $planner = ($this->options['strategy'] ?? $project->generation_strategy) === 'template' ? app(TemplateStoryboardPlanner::class) : app(AIStoryboardPlanner::class);
            $plan = $planner->plan($project, $user, $this->options + ['idempotency_key' => 'storyboard:'.$this->generationJobId]);
            $this->stage('Saving scene plan');
            $version = $boards->apply($project, $plan, $user);
            $this->succeed(['storyboard_version' => $version, 'scenes' => count($plan->scenes), 'origin' => $plan->origin], 'Storyboard ready');
            if ($this->options['continue'] ?? false) {
                $workflow->generateAllScenes($project->fresh(), $user, $this->options);
            }
        } catch (Throwable $e) {
            $project->update(['status' => $project->scenes()->exists() ? ProductionStatus::Planned : ProductionStatus::PackageApproved]);
            $this->handleError($e);
        }
    }
}

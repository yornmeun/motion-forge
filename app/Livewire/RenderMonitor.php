<?php

namespace App\Livewire;

use App\Models\Project;
use App\Models\RenderJob;
use App\Services\Production\ProductionWorkflow;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** Live render status (polls while a render is active). Shows only real stages and counters. */
class RenderMonitor extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);
        $this->project = $project;
    }

    public function cancel(string $id, ProductionWorkflow $workflow): void
    {
        $rj = RenderJob::where('project_id', $this->project->id)->findOrFail($id);
        Gate::authorize('produce', $this->project);
        $workflow->cancelRender($rj);
    }

    public function render(ProductionWorkflow $workflow)
    {
        $rj = $this->project->renderJobs()->first();
        $scenes = $this->project->scenes()->get(['id', 'sequence', 'status', 'error', 'kind']);
        $sceneJobs = $rj ? \App\Models\GenerationJob::where('project_id', $this->project->id)->where('type', 'render_scene')->where('idempotency_key', 'like', '%:'.$rj->id.'%')->get()->keyBy('scene_id') : collect();

        return view('livewire.render-monitor', [
            'rj' => $rj, 'progress' => $rj ? $workflow->renderProgress($rj) : null, 'scenes' => $scenes, 'sceneJobs' => $sceneJobs,
            'active' => $rj && in_array($rj->status->value, ['queued', 'running'], true),
        ]);
    }
}

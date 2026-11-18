<?php

namespace App\Http\Controllers;

use App\Models\GenerationJob;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\Scene;
use App\Services\Production\ProductionWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

class RenderController extends Controller
{
    public function queue(Request $request): View
    {
        $ids = $request->user()->projects()->pluck('id');

        return view('render.queue', [
            'renders' => RenderJob::with('project')->whereIn('project_id', $ids)->latest()->paginate(15),
            'jobs' => GenerationJob::with('project', 'scene')->whereIn('project_id', $ids)->latest()->take(40)->get(),
        ]);
    }

    public function start(Request $request, Project $project, ProductionWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('produce', $project);
        try {
            $workflow->startRender($project, $request->user());
        } catch (Throwable $e) {
            return back()->withErrors(['render' => $e->getMessage()]);
        }

        return redirect()->route('projects.show', [$project, 'tab' => 'render'])->with('status', 'Render started. Unchanged scenes are reused from earlier renders.');
    }

    public function scene(Request $request, Project $project, Scene $scene, ProductionWorkflow $workflow): RedirectResponse|JsonResponse
    {
        Gate::authorize('produce', $project);
        abort_unless($scene->project_id === $project->id, 404);
        try {
            $job = $workflow->renderScene($scene, $request->user());
        } catch (Throwable $e) {
            return $request->expectsJson() ? response()->json(['message' => $e->getMessage()], 422) : back()->withErrors(['render' => $e->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['job' => $job->id]) : back()->with('status', "Scene {$scene->sequence} render queued.");
    }

    public function cancel(Request $request, RenderJob $renderJob, ProductionWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('produce', $renderJob->project);
        $workflow->cancelRender($renderJob);

        return back()->with('status', 'Cancellation requested. Running scene renders stop at their next checkpoint.');
    }

    /** Polled by the UI: real stages and counters only. */
    public function status(Project $project, ProductionWorkflow $workflow): JsonResponse
    {
        Gate::authorize('view', $project);
        $rj = $project->renderJobs()->first();

        return response()->json([
            'project_status' => $project->status->value,
            'render' => $rj ? ['id' => $rj->id, 'status' => $rj->status->value, 'stage' => $rj->stage, 'error' => $rj->error, 'progress' => $workflow->renderProgress($rj)] : null,
            'jobs' => $project->generationJobs()->whereIn('status', ['queued', 'running'])->get(['id', 'type', 'status', 'stage', 'progress_current', 'progress_total', 'scene_id']),
            'scenes' => $project->scenes()->get(['id', 'sequence', 'status', 'error'])->map(fn ($s) => ['id' => $s->id, 'sequence' => $s->sequence, 'status' => $s->status, 'error' => $s->error]),
        ]);
    }
}

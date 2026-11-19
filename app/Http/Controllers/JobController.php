<?php

namespace App\Http\Controllers;

use App\Enums\JobStatus;
use App\Models\GenerationJob;
use App\Services\Production\ProductionWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class JobController extends Controller
{
    public function cancel(GenerationJob $job, ProductionWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('produce', $job->project);
        $workflow->cancelJob($job);

        return back()->with('status', 'Cancellation requested.');
    }

    /** Retry a failed or budget-blocked job (scene-level recovery). Budget retries count as approval. */
    public function retry(Request $request, GenerationJob $job, ProductionWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('produce', $job->project);
        abort_unless(in_array($job->status, [JobStatus::Failed, JobStatus::WaitingApproval, JobStatus::Cancelled], true), 422, 'Only failed, cancelled or waiting jobs can be retried.');
        $options = ($job->payload ?? []) + ['approved' => $job->status === JobStatus::WaitingApproval && $request->boolean('approve')];
        $user = $request->user();
        match ($job->type) {
            'storyboard' => $workflow->generateStoryboard($job->project, $user, $options),
            'scene_code', 'video_clip' => $workflow->regenerateScene($job->scene, $user, $options),
            'render_scene' => $workflow->renderScene($job->scene, $user),
            'validate_package' => $workflow->validatePackage($job->project->latestPackage, $user, $options),
            default => abort(422, 'This job type cannot be retried here.'),
        };

        return back()->with('status', 'Retry queued.');
    }
}

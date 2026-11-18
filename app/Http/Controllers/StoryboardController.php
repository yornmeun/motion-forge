<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Scene;
use App\Models\SceneVersion;
use App\Services\Production\LockedNarrationException;
use App\Services\Production\ProductionWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Throwable;

class StoryboardController extends Controller
{
    public function generate(Request $request, Project $project, ProductionWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('produce', $project);
        $d = $request->validate(['strategy' => ['nullable', Rule::in(['ai', 'template'])], 'continue' => ['boolean'], 'approved' => ['boolean']]);
        if ($project->scenes()->exists() && ! $request->boolean('replace')) {
            return back()->withErrors(['storyboard' => 'This project already has scenes. Confirm replacing them to generate a new storyboard (scene versions are discarded; storyboard files are kept).']);
        }
        try {
            $workflow->generateStoryboard($project, $request->user(), array_filter($d + ['strategy' => $project->generation_strategy], fn ($v) => $v !== null));
        } catch (LockedNarrationException $e) {
            return back()->withErrors(['storyboard' => $e->getMessage()]);
        }

        return redirect()->route('projects.show', [$project, 'tab' => 'scenes'])->with('status', 'Storyboard generation queued.');
    }

    public function generateScenes(Request $request, Project $project, ProductionWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('produce', $project);
        try {
            $batch = $workflow->generateAllScenes($project, $request->user(), ['approved' => $request->boolean('approved')]);
        } catch (Throwable $e) {
            return back()->withErrors(['scenes' => $e->getMessage()]);
        }

        return back()->with('status', $batch ? 'Animation generation queued for '.$batch->totalJobs.' scene(s).' : 'Nothing to generate (jobs already running).');
    }

    /** Native-video projects without a narration package: add a prompt-driven scene. */
    public function addScene(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_if($project->production_mode->requiresLockedPackage() && $project->approvedPackage, 422, 'Scenes of narrated projects come from the storyboard.');
        $d = $request->validate(['prompt' => ['required', 'string', 'min:10', 'max:2000'], 'duration' => ['required', 'numeric', 'min:3', 'max:15']]);
        $last = $project->scenes()->orderByDesc('sequence')->first();
        $start = $last ? (float) $last->end_time : 0.0;
        $scene = Scene::create(['project_id' => $project->id, 'sequence' => ($last?->sequence ?? 0) + 1, 'start_time' => $start, 'end_time' => round($start + $d['duration'], 3), 'kind' => 'story', 'source' => 'ai_video', 'status' => 'planned']);
        $v = SceneVersion::create(['scene_id' => $scene->id, 'version' => 1, 'plan' => ['visual_description' => $d['prompt'], 'video_prompt' => $d['prompt'], 'transition' => 'none', 'music' => 'dark_drone', 'sound_effects' => [], 'lighting' => 'neutral', 'background' => 'hallway'], 'origin' => 'manual', 'created_by' => $request->user()->id]);
        $scene->update(['current_version_id' => $v->id]);

        return back()->with('status', "Scene {$scene->sequence} added.");
    }
}

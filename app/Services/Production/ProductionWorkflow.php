<?php

namespace App\Services\Production;

use App\Enums\AICapability;
use App\Enums\JobStatus;
use App\Enums\ProductionMode;
use App\Enums\ProductionStatus;
use App\Jobs\AlignVoiceoverJob;
use App\Jobs\FinalizeProductionJob;
use App\Jobs\GenerateCaptionJob;
use App\Jobs\GenerateSceneCodeJob;
use App\Jobs\GenerateStoryboardJob;
use App\Jobs\GenerateThumbnailJob;
use App\Jobs\GenerateVideoClipJob;
use App\Jobs\MixAudioJob;
use App\Jobs\RenderFullVideoJob;
use App\Jobs\RenderSceneJob;
use App\Jobs\RunQualityControlJob;
use App\Jobs\ValidateProductionPackageJob;
use App\Models\GenerationJob;
use App\Models\ProductionPackage;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\Scene;
use App\Models\User;
use App\Services\AI\ProviderRouter;
use App\Services\Media\Ffmpeg;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Production coordinator. Enforces the gates (approved + unchanged package before any locked-story
 * production; validated code before rendering) and dispatches queue work. Nothing heavy runs in
 * the HTTP request.
 */
class ProductionWorkflow
{
    public function __construct(private PackageService $packages) {}

    /** Create (or reuse an active) tracked job row. Returns [job, created]. */
    public function track(Project $project, ?Scene $scene, ?User $user, string $type, string $key, array $payload = []): array
    {
        $active = GenerationJob::where('idempotency_key', $key)->whereIn('status', [JobStatus::Queued->value, JobStatus::Running->value])->first();
        if ($active) {
            return [$active, false];
        }
        GenerationJob::where('idempotency_key', $key)->get()->each(fn ($j) => $j->update(['idempotency_key' => $key.':'.$j->id]));

        return [GenerationJob::create(['project_id' => $project->id, 'scene_id' => $scene?->id, 'user_id' => $user?->id, 'type' => $type, 'status' => JobStatus::Queued, 'stage' => 'Queued', 'idempotency_key' => $key, 'payload' => $payload ?: null]), true];
    }

    public function validatePackage(ProductionPackage $package, User $user, array $options = []): GenerationJob
    {
        [$job, $new] = $this->track($package->project, null, $user, 'validate_package', 'validate:'.$package->id, $options);
        if ($new) {
            ValidateProductionPackageJob::dispatch($package->id, $job->id, $user->id, $options);
        }

        return $job;
    }

    public function automaticProductionBlocker(Project $project, User $user): ?string
    {
        if ($project->scenes()->whereHas('assets')->exists()) {
            return 'This project already has scene assets. Review or remove those scenes before automatic production can replace them.';
        }

        $router = app(ProviderRouter::class);
        if ($project->generation_strategy === 'ai') {
            try {
                $router->forProject($project, $user, AICapability::ScenePlanning);
                if ($project->production_mode !== ProductionMode::NativeVideo) {
                    $router->forProject($project, $user, AICapability::AnimationCode);
                }
            } catch (\Throwable $e) {
                return 'Automatic production needs a connected planning'.($project->production_mode === ProductionMode::NativeVideo ? '' : ' and animation-code').' model: '.$e->getMessage();
            }
        }

        if ($project->production_mode === ProductionMode::NativeVideo) {
            try {
                $router->forProject($project, $user, AICapability::NativeVideo);
            } catch (\Throwable $e) {
                return 'Automatic production needs a connected native-video model: '.$e->getMessage();
            }
        }

        return null;
    }

    public function startAutomaticProduction(Project $project, User $user, array $options = []): GenerationJob
    {
        if (! $project->approvedPackage) {
            throw new RuntimeException('Approve a valid production package before generating the video.');
        }
        if ($blocker = $this->automaticProductionBlocker($project, $user)) {
            throw new RuntimeException($blocker);
        }

        return $this->generateStoryboard($project, $user, array_merge([
            'strategy' => $project->generation_strategy,
            'continue' => true,
            'auto_render' => true,
        ], $options));
    }

    public function startRenderWhenReady(Project $project, User $user): bool
    {
        return Cache::lock('automatic-render:'.$project->id, 30)->block(5, function () use ($project, $user): bool {
            $project->refresh();
            if ($project->renderJobs()->where('type', 'full')->whereIn('status', [JobStatus::Queued, JobStatus::Running])->exists()) {
                return true;
            }

            if ($project->generationJobs()->whereIn('type', ['storyboard', 'scene_code', 'video_clip'])->whereIn('status', [JobStatus::Queued, JobStatus::Running])->exists()) {
                return false;
            }

            if ($this->renderBlockers($project)) {
                return false;
            }

            $this->startRender($project, $user);

            return true;
        });
    }

    public function generateStoryboard(Project $project, User $user, array $options = []): GenerationJob
    {
        $package = $this->packages->assertLocked($project);
        [$job, $new] = $this->track($project, null, $user, 'storyboard', "storyboard:{$project->id}:{$package->version}", $options);
        if ($new) {
            GenerateStoryboardJob::dispatch($project->id, $user->id, $job->id, $options);
        }

        return $job;
    }

    public function generateAllScenes(Project $project, User $user, array $options = []): ?Batch
    {
        if ($project->production_mode->requiresLockedPackage()) {
            $this->packages->assertLocked($project);
        }
        $jobs = [];
        $videoJobs = [];
        foreach ($project->scenes()->where('source', 'ai_video')->get() as $scene) {
            if ($scene->approvedClip()) {
                continue;
            }
            [$job, $new] = $this->track($project, $scene, $user, 'video_clip', "video_clip:{$scene->id}", $options);
            if ($new) {
                $videoJobs[] = [$scene, $job];
            }
        }
        foreach ($project->scenes()->where('source', 'canvas')->get() as $scene) {
            [$job, $new] = $this->track($project, $scene, $user, 'scene_code', "scene_code:{$scene->id}", $options);
            if ($new) {
                $jobs[] = new GenerateSceneCodeJob($scene->id, $user->id, $job->id, collect($options)->except(['continue'])->all());
            }
        }
        if ($jobs || $videoJobs) {
            $project->update(['status' => ProductionStatus::Generating]);
        }
        foreach ($videoJobs as [$scene, $job]) {
            GenerateVideoClipJob::dispatch($scene->id, $user->id, $job->id, $options);
        }
        if (! $jobs && ! $videoJobs) {
            if ($options['auto_render'] ?? false) {
                $this->startRenderWhenReady($project, $user);
            }

            return null;
        }
        $projectId = $project->id;
        $userId = $user->id;

        if (! $jobs) {
            return null;
        }

        return Bus::batch($jobs)->name("scene-code:{$project->id}")->allowFailures()
            ->finally(function () use ($projectId, $userId, $options) {
                $p = Project::find($projectId);
                if ($p && $p->status === ProductionStatus::Generating) {
                    $p->update(['status' => ProductionStatus::ReadyForReview]);
                    $p->log('scenes.generated', 'Scene generation finished; review the previews.');
                }
                if ($p && ($options['auto_render'] ?? false)) {
                    $user = User::find($userId);
                    if ($user) {
                        app(self::class)->startRenderWhenReady($p, $user);
                    }
                }
            })->dispatch();
    }

    public function regenerateScene(Scene $scene, User $user, array $options = []): GenerationJob
    {
        $project = $scene->project;
        if ($project->production_mode->requiresLockedPackage()) {
            $this->packages->assertLocked($project);
        }
        if ($scene->source === 'ai_video') {
            [$job, $new] = $this->track($project, $scene, $user, 'video_clip', "video_clip:{$scene->id}", $options);
            if ($new) {
                GenerateVideoClipJob::dispatch($scene->id, $user->id, $job->id, $options);
            }

            return $job;
        }
        [$job, $new] = $this->track($project, $scene, $user, 'scene_code', "scene_code:{$scene->id}", $options);
        if ($new) {
            GenerateSceneCodeJob::dispatch($scene->id, $user->id, $job->id, $options);
        }

        return $job;
    }

    public function renderScene(Scene $scene, User $user): GenerationJob
    {
        $this->assertSceneRenderable($scene);
        [$job, $new] = $this->track($scene->project, $scene, $user, 'render_scene', "render_scene:{$scene->id}");
        if ($new) {
            RenderSceneJob::dispatch($scene->id, $job->id);
        }

        return $job;
    }

    /** @return list<string> blocking problems */
    public function renderBlockers(Project $project): array
    {
        $problems = [];
        if ($project->production_mode->requiresLockedPackage()) {
            try {
                $this->packages->assertLocked($project);
            } catch (LockedNarrationException $e) {
                $problems[] = $e->getMessage();
            }
        }
        $scenes = $project->scenes()->with('currentVersion')->get();
        if ($scenes->isEmpty()) {
            $problems[] = 'There are no scenes. Generate a storyboard first.';
        }
        foreach ($scenes as $s) {
            try {
                $this->assertSceneRenderable($s);
            } catch (RuntimeException $e) {
                $problems[] = $e->getMessage();
            }
        }
        if (! app(Ffmpeg::class)->available()) {
            $problems[] = 'FFmpeg is not available (set MOTIONFORGE_FFMPEG).';
        }

        return $problems;
    }

    public function assertSceneRenderable(Scene $scene): void
    {
        if ($scene->source === 'ai_video') {
            if (! $scene->approvedClip()) {
                throw new RuntimeException("Scene {$scene->sequence}: approve an AI clip first.");
            }

            return;
        }
        if (! $scene->currentVersion?->codeIsValid()) {
            throw new RuntimeException("Scene {$scene->sequence}: animation code is missing or failed validation.");
        }
    }

    public function startRender(Project $project, User $user): RenderJob
    {
        if ($blockers = $this->renderBlockers($project)) {
            throw new RuntimeException(implode(' ', array_slice($blockers, 0, 3)));
        }
        $active = $project->renderJobs()->whereIn('status', ['queued', 'running'])->where('type', 'full')->first();
        if ($active) {
            return $active;
        }
        $scenes = $project->scenes()->with('currentVersion', 'project')->get();
        $rj = RenderJob::create(['project_id' => $project->id, 'user_id' => $user->id, 'type' => 'full', 'status' => JobStatus::Queued, 'stage' => 'Queued', 'scene_ids' => $scenes->pluck('id')->all()]);
        $sceneJobs = [];
        foreach ($scenes as $scene) {
            if (RenderSceneJob::segmentFor($scene)) {
                continue;
            } // unchanged scenes are reused
            [$job] = $this->track($project, $scene, $user, 'render_scene', "render_scene:{$scene->id}:{$rj->id}", ['render_job_id' => $rj->id, 'frames' => (int) round($scene->duration() * $project->frame_rate)]);
            $sceneJobs[] = new RenderSceneJob($scene->id, $job->id, $rj->id);
        }
        $project->update(['status' => ProductionStatus::Rendering]);
        $project->log('render.started', 'Full render started ('.count($sceneJobs).' of '.$scenes->count().' scenes need rendering).', [], $user);
        $rjId = $rj->id;
        $count = $scenes->count();
        $chain = [new AlignVoiceoverJob($rjId)];
        if ($sceneJobs) {
            $chain[] = Bus::batch($sceneJobs)->name("render:{$rjId}")->allowFailures()
                ->progress(fn (Batch $b) => RenderJob::whereKey($rjId)->update(['stage' => 'Rendering scenes ('.($b->processedJobs()).'/'.$b->totalJobs.' changed scenes)']));
        }
        array_push($chain, new MixAudioJob($rjId), new RenderFullVideoJob($rjId), new GenerateCaptionJob($rjId), new GenerateThumbnailJob($rjId), new RunQualityControlJob($rjId), new FinalizeProductionJob($rjId));
        try {
            Bus::chain($chain)->dispatch();
        } catch (\Throwable $e) {
            $rj->update(['status' => JobStatus::Failed, 'stage' => 'Could not be queued', 'error' => $e->getMessage(), 'finished_at' => now()]);
            $project->update(['status' => ProductionStatus::Failed]);
            throw $e;
        }

        return $rj;
    }

    public function cancelRender(RenderJob $rj): void
    {
        $rj->update(['cancel_requested_at' => now()]);
        Cache::put('render-cancel:'.$rj->id, true, now()->addHours(6));
        GenerationJob::where('project_id', $rj->project_id)->where('type', 'render_scene')->whereIn('status', ['queued', 'running'])->update(['cancel_requested_at' => now()]);
        if (in_array($rj->status, [JobStatus::Queued], true)) {
            $rj->update(['status' => JobStatus::Cancelled, 'stage' => 'Cancelled', 'finished_at' => now()]);
        }
        $rj->project->update(['status' => $rj->project->scenes()->exists() ? ProductionStatus::ReadyForReview : ProductionStatus::Draft]);
    }

    public function cancelJob(GenerationJob $job): void
    {
        $job->update(['cancel_requested_at' => now()]);
        if ($job->status === JobStatus::Queued) {
            $job->update(['status' => JobStatus::Cancelled, 'stage' => 'Cancelled', 'finished_at' => now()]);
        }
    }

    /** Real progress for a render: completed scene renders + frame counters of running scenes. */
    public function renderProgress(RenderJob $rj): array
    {
        $total = count($rj->scene_ids ?? []);
        $jobs = GenerationJob::where('project_id', $rj->project_id)->where('type', 'render_scene')->where('idempotency_key', 'like', '%:'.$rj->id.'%')->get();
        $frames = $jobs->sum('progress_current');
        $framesTotal = $jobs->sum(fn ($j) => (int) ($j->payload['frames'] ?? $j->progress_total ?? 0));

        return ['scenes_total' => $total, 'scenes_to_render' => $jobs->count(), 'scenes_done' => $jobs->where('status', JobStatus::Succeeded)->count(), 'scenes_failed' => $jobs->where('status', JobStatus::Failed)->count(), 'frames' => (int) $frames, 'frames_total' => $framesTotal ? (int) $framesTotal : null];
    }
}

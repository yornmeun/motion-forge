<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Jobs\GenerateSceneCodeJob;
use App\Jobs\GenerateStoryboardJob;
use App\Jobs\RenderSceneJob;
use App\Jobs\ValidateProductionPackageJob;
use App\Models\GenerationJob;
use App\Models\Scene;
use App\Models\SceneVersion;
use App\Services\Production\Data\CodeResult;
use App\Services\Production\LockedNarrationException;
use App\Services\Production\ProductionWorkflow;
use App\Services\Production\TemplateSceneDirector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MotionForgeFixtures;
use Tests\TestCase;

class QueueAndJobsTest extends TestCase
{
    use MotionForgeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRegistry();
        Storage::fake('local');
    }

    public function test_storyboard_generation_is_queued_once_and_needs_an_approved_package(): void
    {
        Bus::fake();
        $user = $this->userWithRole();
        $project = $this->project($user);
        $workflow = app(ProductionWorkflow::class);

        try {
            $workflow->generateStoryboard($project, $user);
            $this->fail('Storyboards need an approved package.');
        } catch (LockedNarrationException) {
        }
        Bus::assertNotDispatched(GenerateStoryboardJob::class);

        $this->approvedPackage($project, $user);
        $a = $workflow->generateStoryboard($project->fresh(), $user);
        $b = $workflow->generateStoryboard($project->fresh(), $user); // double click

        $this->assertSame($a->id, $b->id);
        Bus::assertDispatchedTimes(GenerateStoryboardJob::class, 1);
    }

    public function test_storyboard_http_endpoint_dispatches_the_job(): void
    {
        Bus::fake();
        $user = $this->userWithRole();
        $project = $this->project($user);
        $this->approvedPackage($project, $user);

        $this->actingAs($user)->post("/projects/{$project->id}/storyboard", ['strategy' => 'template'])->assertRedirect();
        Bus::assertDispatched(GenerateStoryboardJob::class, fn ($j) => $j->projectId === $project->id);
    }

    public function test_package_validation_is_dispatched_to_the_queue(): void
    {
        Bus::fake();
        $user = $this->userWithRole();
        $project = $this->project($user);
        $pkg = $project->packages()->create(['version' => 1, 'status' => 'draft', 'source' => 'upload', 'srt_text' => $this->srt()]);
        app(ProductionWorkflow::class)->validatePackage($pkg, $user);
        Bus::assertDispatched(ValidateProductionPackageJob::class);
    }

    public function test_rendering_is_blocked_until_scene_code_is_valid(): void
    {
        Bus::fake();
        $user = $this->userWithRole();
        $project = $this->project($user);
        $this->approvedPackage($project, $user);
        $scene = Scene::create(['project_id' => $project->id, 'sequence' => 1, 'start_time' => 0, 'end_time' => 2.5, 'cue_start' => 0, 'cue_end' => 0, 'narration_reference' => self::LINES[0], 'kind' => 'story', 'source' => 'canvas', 'status' => 'planned']);
        $v = SceneVersion::create(['scene_id' => $scene->id, 'version' => 1, 'plan' => [], 'code' => 'bad', 'code_validation' => ['ok' => false, 'errors' => ['x']], 'origin' => 'manual']);
        $scene->update(['current_version_id' => $v->id]);

        $this->actingAs($user)->postJson("/projects/{$project->id}/scenes/{$scene->id}/render")->assertStatus(422);
        $blockers = app(ProductionWorkflow::class)->renderBlockers($project->fresh());
        $this->assertTrue(collect($blockers)->contains(fn ($b) => str_contains($b, 'failed validation')));
        $this->actingAs($user)->post("/projects/{$project->id}/render")->assertSessionHasErrors('render');
        Bus::assertNotDispatched(RenderSceneJob::class);

        $v->update(['code_validation' => ['ok' => true]]);
        $this->actingAs($user)->postJson("/projects/{$project->id}/scenes/{$scene->id}/render")->assertOk();
        Bus::assertDispatched(RenderSceneJob::class);
    }

    public function test_a_failing_generator_marks_the_scene_and_job_failed(): void
    {
        $user = $this->userWithRole();
        $project = $this->project($user);
        $this->approvedPackage($project, $user);
        $scene = Scene::create(['project_id' => $project->id, 'sequence' => 1, 'start_time' => 0, 'end_time' => 2.5, 'cue_start' => 0, 'cue_end' => 0, 'narration_reference' => self::LINES[0], 'kind' => 'story', 'source' => 'canvas', 'status' => 'planned']);
        [$job] = app(ProductionWorkflow::class)->track($project, $scene, $user, 'scene_code', 'scene_code:'.$scene->id);

        $this->app->instance(TemplateSceneDirector::class, new class {
            public function generate(): never
            {
                throw new LockedNarrationException('Simulated permanent failure.');
            }
        });
        $j = new GenerateSceneCodeJob($scene->id, $user->id, $job->id, ['strategy' => 'template']);
        $j->withFakeQueueInteractions();
        $j->handle();

        $j->assertFailed();
        $this->assertSame('failed', $scene->fresh()->status);
        $this->assertStringContainsString('Simulated permanent failure', $scene->fresh()->error);
    }

    public function test_invalid_generated_code_keeps_the_previous_version(): void
    {
        $user = $this->userWithRole();
        $project = $this->project($user);
        $this->approvedPackage($project, $user);
        $scene = Scene::create(['project_id' => $project->id, 'sequence' => 1, 'start_time' => 0, 'end_time' => 2.5, 'cue_start' => 0, 'cue_end' => 0, 'narration_reference' => self::LINES[0], 'kind' => 'story', 'source' => 'canvas', 'status' => 'planned']);
        $v1 = SceneVersion::create(['scene_id' => $scene->id, 'version' => 1, 'plan' => ['emotion' => 'neutral'], 'origin' => 'template']);
        $scene->update(['current_version_id' => $v1->id]);
        [$job] = app(ProductionWorkflow::class)->track($project, $scene, $user, 'scene_code', 'scene_code:'.$scene->id);

        $this->app->instance(TemplateSceneDirector::class, new class {
            public function generate(): CodeResult
            {
                return new CodeResult('while(true){}', ['ok' => false, 'errors' => ['loops are not allowed']], 'template', null, 1);
            }
        });
        (new GenerateSceneCodeJob($scene->id, $user->id, $job->id, ['strategy' => 'template']))->handle();

        $scene->refresh();
        $this->assertSame($v1->id, $scene->current_version_id);
        $this->assertSame('failed', $scene->status);
        $this->assertSame(2, $scene->versions()->count()); // the invalid attempt is kept for inspection
        $this->assertSame(JobStatus::Succeeded, GenerationJob::find($job->id)->status);
    }
}

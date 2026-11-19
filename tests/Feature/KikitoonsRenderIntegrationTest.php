<?php

namespace Tests\Feature;

use App\Models\SceneVersion;
use App\Services\Media\Ffmpeg;
use App\Services\Production\StoryboardService;
use App\Services\Production\TemplateSceneDirector;
use App\Services\Production\TemplateStoryboardPlanner;
use App\Services\Rendering\KikitoonsEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MotionForgeFixtures;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Real render integration: template code → static validation → headless Chrome probe →
 * still preview → short MP4 segment through FFmpeg. Skipped when Chrome or FFmpeg is missing.
 * Slow (tens of seconds); exclude with --exclude-group=render.
 */
#[Group('render')]
class KikitoonsRenderIntegrationTest extends TestCase
{
    use MotionForgeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $chrome = (string) config('motionforge.chrome');
        if (! is_file($chrome) || ! app(Ffmpeg::class)->available()) {
            $this->markTestSkipped('Chrome (MOTIONFORGE_CHROME) or FFmpeg is not available.');
        }
        $this->seedRegistry();
        $this->seed(\Database\Seeders\LibrarySeeder::class);
        Storage::fake('local');
    }

    public function test_a_template_scene_validates_previews_and_renders(): void
    {
        $user = $this->userWithRole();
        $project = $this->project($user);
        $this->approvedPackage($project, $user);
        $plan = app(TemplateStoryboardPlanner::class)->plan($project, $user);
        app(StoryboardService::class)->apply($project, $plan, $user);
        $scene = $project->scenes()->where('kind', 'story')->with('currentVersion', 'project')->first();

        $result = app(TemplateSceneDirector::class)->generate($scene, $scene->plan(), $user);
        $this->assertTrue($result->validation['ok'], 'Validator errors: '.json_encode($result->validation['errors'] ?? []));

        $engine = app(KikitoonsEngine::class);
        $probe = $engine->probeCode($scene, $result->code);
        $this->assertTrue($probe['ok'], 'Probe failed: '.$probe['message']);

        $v = SceneVersion::create(['scene_id' => $scene->id, 'version' => 2, 'plan' => $scene->plan(), 'code' => $result->code, 'code_validation' => $result->validation, 'origin' => 'template']);
        $scene->update(['current_version_id' => $v->id]);
        $scene = $scene->fresh(['currentVersion', 'project']);

        $still = $engine->generatePreview($scene);
        $size = getimagesize(Storage::disk('local')->path($still));
        $this->assertSame([1080, 1920], [$size[0], $size[1]]);

        $mp4 = $engine->renderScene($scene);
        $probeInfo = app(Ffmpeg::class)->probe(Storage::disk('local')->path($mp4));
        $video = collect($probeInfo['streams'])->firstWhere('codec_type', 'video');
        $this->assertSame(1080, (int) $video['width']);
        $this->assertSame(1920, (int) $video['height']);
        $this->assertEqualsWithDelta($scene->duration(), $probeInfo['duration'], 0.2);

        // an unchanged scene is served from the cache without re-rendering
        $this->assertSame($mp4, $engine->renderScene($scene));
    }
}

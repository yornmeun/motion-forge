<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\RenderJob;
use App\Models\SceneVersion;
use App\Services\Media\Ffmpeg;
use App\Services\Production\PackageService;
use App\Services\Production\ProductionWorkflow;
use App\Services\Production\StoryboardService;
use App\Services\Production\TemplateSceneDirector;
use App\Services\Production\TemplateStoryboardPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MotionForgeFixtures;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * End-to-end render on the sync queue: AlignVoiceover → RenderScene batch → MixAudio →
 * RenderFullVideo → GenerateCaption → GenerateThumbnail → RunQualityControl → FinalizeProduction.
 * The "voiceover" is a synthetic tone that sounds during each SRT cue. Needs Chrome + FFmpeg; slow.
 */
#[Group('render')]
class FullRenderPipelineTest extends TestCase
{
    use MotionForgeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_file((string) config('motionforge.chrome')) || ! app(Ffmpeg::class)->available()) {
            $this->markTestSkipped('Chrome (MOTIONFORGE_CHROME) or FFmpeg is not available.');
        }
        $this->seedRegistry();
        $this->seed(\Database\Seeders\LibrarySeeder::class);
        Storage::fake('local');
        config(['queue.default' => 'sync']);
    }

    public function test_full_render_produces_a_checked_final_video(): void
    {
        $user = $this->userWithRole();
        $project = $this->project($user);

        // synthetic voice: a tone during each cue (n × 2.5 s … +2.3 s), silence between
        $ff = app(Ffmpeg::class);
        $wav = storage_path('app/tmp/e2e-voice-'.uniqid().'.wav');
        @mkdir(dirname($wav), 0777, true);
        $ff->mustRun([$ff->bin(), '-y', '-v', 'error', '-f', 'lavfi', '-i', "aevalsrc=0.3*sin(220*2*PI*t)*lt(mod(t\\,2.5)\\,2.3):s=44100:d=15", $wav], 60);
        $voice = app(PackageService::class)->storeFile($project, $user, 'voiceover', $wav, 'voice.wav', 'audio/wav');
        @unlink($wav);

        $package = $project->packages()->create([
            'version' => 1, 'status' => 'valid', 'source' => 'upload', 'voiceover_file_id' => $voice->id,
            'locked_story' => 'Synthetic test story.', 'narration_text' => implode(' ', self::LINES), 'srt_text' => $this->srt(),
            'validation_report' => ['status' => 'valid', 'checks' => [], 'audio_duration' => 15.0, 'srt_end' => 14.8],
        ]);
        app(PackageService::class)->approve($package, $user);

        $plan = app(TemplateStoryboardPlanner::class)->plan($project, $user);
        app(StoryboardService::class)->apply($project, $plan, $user);
        foreach ($project->scenes()->with('currentVersion', 'project')->get() as $scene) {
            $res = app(TemplateSceneDirector::class)->generate($scene, $scene->plan() + ['kind' => $scene->kind], $user);
            $this->assertTrue($res->validation['ok'], "Scene {$scene->sequence}: ".json_encode($res->validation['errors'] ?? []));
            $v = SceneVersion::create(['scene_id' => $scene->id, 'version' => 2, 'plan' => $scene->plan(), 'code' => $res->code, 'code_validation' => $res->validation, 'origin' => 'template']);
            $scene->update(['current_version_id' => $v->id, 'status' => 'generated']);
        }

        $this->assertSame([], app(ProductionWorkflow::class)->renderBlockers($project->fresh()));
        $rj = app(ProductionWorkflow::class)->startRender($project->fresh(), $user);
        $rj = RenderJob::find($rj->id);

        $this->assertSame(JobStatus::Succeeded, $rj->status, "Render {$rj->status->value} at '{$rj->stage}': {$rj->error}");
        $project->refresh();
        $final = $project->finalVideo();
        $this->assertNotNull($final, 'No delivery video was recorded.');
        $abs = Storage::disk('local')->path($final->path);
        $probe = $ff->probe($abs);
        $this->assertEqualsWithDelta((float) $project->scenes()->max('end_time'), $probe['duration'], 0.6);

        $checks = $project->qualityChecks()->where('kind', 'technical')->get()->keyBy('check_key');
        foreach (['readable', 'resolution', 'frame_rate', 'codecs', 'duration', 'audio_present'] as $key) {
            $this->assertSame('pass', $checks[$key]->status ?? null, "{$key}: ".($checks[$key]->message ?? 'missing'));
        }
        $this->assertNotNull($project->thumbnails()->first(), 'No thumbnail recorded.');
        $this->assertSame('pass', $checks['thumbnail']->status ?? null, 'thumbnail: '.($checks['thumbnail']->message ?? 'missing'));
        $this->assertNotNull($project->artifacts()->where('type', 'srt_final')->first(), 'No final SRT recorded.');

        // a second render reuses every unchanged scene segment
        $rj2 = app(ProductionWorkflow::class)->startRender($project->fresh(), $user);
        $this->assertStringContainsString('0 of', (string) $project->activity()->where('action', 'render.started')->latest('id')->first()?->description);
        $this->assertSame(JobStatus::Succeeded, RenderJob::find($rj2->id)->status);
    }
}

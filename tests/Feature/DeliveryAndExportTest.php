<?php

namespace Tests\Feature;

use App\Models\RenderArtifact;
use App\Services\Media\Ffmpeg;
use App\Services\Rendering\QualityControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MotionForgeFixtures;
use Tests\TestCase;

class DeliveryAndExportTest extends TestCase
{
    use MotionForgeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRegistry();
    }

    public function test_downloads_require_access_and_a_file_inside_the_project(): void
    {
        Storage::fake('local');
        $owner = $this->userWithRole();
        $stranger = $this->userWithRole();
        $project = $this->project($owner);

        // nothing rendered yet
        $this->actingAs($owner)->get("/projects/{$project->id}/download/video")->assertNotFound();

        $path = $project->storageDir('final/final.mp4');
        Storage::disk('local')->put($path, 'fake-mp4-bytes');
        RenderArtifact::create(['project_id' => $project->id, 'type' => 'final_video', 'path' => $path, 'size' => 14, 'is_delivery' => true]);

        $this->actingAs($stranger)->get("/projects/{$project->id}/download/video")->assertForbidden();
        $this->get('/logout');
        $this->actingAs($owner)->get("/projects/{$project->id}/download/video")->assertOk()->assertDownload('test-project-final.mp4');
        $this->actingAs($owner)->get("/projects/{$project->id}/download/passwords")->assertNotFound();
    }

    public function test_artifact_paths_outside_the_project_directory_are_refused(): void
    {
        Storage::fake('local');
        $owner = $this->userWithRole();
        $project = $this->project($owner);
        $other = $this->project($owner, ['title' => 'Other']);
        $foreign = $other->storageDir('final/final.mp4');
        Storage::disk('local')->put($foreign, 'other project');
        RenderArtifact::create(['project_id' => $project->id, 'type' => 'final_video', 'path' => $foreign, 'size' => 13, 'is_delivery' => true]);

        $this->actingAs($owner)->get("/projects/{$project->id}/download/video")->assertNotFound();
    }

    /** Integration: needs a working FFmpeg (MOTIONFORGE_FFMPEG / FFPROBE). Skipped otherwise. */
    public function test_final_export_quality_control_checks_the_real_file(): void
    {
        $ff = app(Ffmpeg::class);
        if (! $ff->available()) {
            $this->markTestSkipped('FFmpeg is not available.');
        }
        Storage::fake('local');
        $owner = $this->userWithRole();
        $project = $this->project($owner, ['width' => 360, 'height' => 640]);
        $rel = $project->storageDir('final/final.mp4');
        $abs = Storage::disk('local')->path($rel);
        @mkdir(dirname($abs), 0777, true);
        $ff->mustRun([$ff->bin(), '-y', '-v', 'error',
            '-f', 'lavfi', '-i', 'testsrc2=size=360x640:rate=30:duration=3',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $abs], 120);
        $final = RenderArtifact::create(['project_id' => $project->id, 'type' => 'final_video', 'path' => $rel, 'size' => filesize($abs), 'is_delivery' => true]);

        $results = collect(app(QualityControl::class)->run($project, $final, 3.0, [['start' => 0.2, 'end' => 1.5], ['start' => 1.6, 'end' => 2.9]]))->keyBy('check_key');

        foreach (['readable', 'resolution', 'aspect_ratio', 'frame_rate', 'codecs', 'duration', 'audio_present', 'subtitle_timing'] as $key) {
            $this->assertSame('pass', $results[$key]['status'], "{$key}: ".$results[$key]['message']);
        }
        // the Kikitoons length rule only warns; a 3 s clip is outside 2:00-3:00
        $this->assertSame('warn', $results['length_range']['status']);

        // a wrong expected duration fails
        $bad = collect(app(QualityControl::class)->run($project, $final, 10.0))->keyBy('check_key');
        $this->assertSame('fail', $bad['duration']['status']);
    }
}

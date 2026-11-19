<?php

namespace Tests\Feature;

use App\Enums\ProductionStatus;
use App\Jobs\ValidateProductionPackageJob;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\Media\Ffmpeg;
use App\Services\Production\PackageService;
use App\Services\Production\PackageValidator;
use App\Services\Production\SceneSheetPlanner;
use App\Services\Production\SrtParser;
use App\Services\Production\TemplateSceneDirector;
use App\Services\Rendering\KikitoonsEngine;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/** A ChatGPT V2 package (scene sheet, registries, one narration track) produced with the free template director. */
class V2PackageProductionTest extends TestCase
{
    use RefreshDatabase;

    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        if (! app(Ffmpeg::class)->available()) {
            $this->markTestSkipped('FFmpeg is not available.');
        }
        $this->work = storage_path('app/tmp/test-v2-'.uniqid());
        File::ensureDirectoryExists($this->work);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->work);
        if (isset($this->project)) {
            File::deleteDirectory(storage_path('app/private/'.$this->project->storageDir()));
        }
        parent::tearDown();
    }

    protected ?Project $project = null;

    public function test_v2_package_is_planned_from_the_scene_sheet(): void
    {
        $user = $this->admin();
        $this->project = $this->landscapeProject($user);

        $package = app(PackageService::class)->createFromArchive($this->project, $user, $this->packageZip(), $this->tone('narration.wav', 26));

        $this->assertNotNull($package->document('scene_sheet'));
        $this->assertNotNull($package->document('character_registry'));
        $this->assertSame('narration.wav', $package->voiceover->original_name);
        $cues = app(SrtParser::class)->parse($package->srt_text);
        $this->assertSame(0.0, $cues[0]['start']);
        $this->assertSame('At two thirteen in the morning, my phone lit up.', $cues[0]['text']);

        $report = app(PackageValidator::class)->validate($package->fresh());
        $package->update(['status' => $report['status'], 'validation_report' => $report]);
        $this->assertSame('valid', $report['status'], json_encode(collect($report['checks'])->where('status', 'fail')->values()));
        $this->assertSame('pass', collect($report['checks'])->firstWhere('key', 'scene_sheet')['status']);
        app(PackageService::class)->approve($package->fresh(), $user, true);

        $scenes = app(SceneSheetPlanner::class)->plan($this->project->fresh(), $user)->scenes;
        $kinds = array_column($scenes, 'kind');
        $this->assertSame(['story', 'end_card'], array_values(array_unique($kinds)), 'Story scenes, then the end card; no host scenes.');
        $this->assertSame(0.0, (float) $scenes[0]['start_time']);
        $this->assertSame(count($scenes), end($scenes)['scene_number']);
        $story = array_values(array_filter($scenes, fn ($s) => $s['kind'] === 'story'));
        $this->assertSame(['SC_001', 'SC_002', 'SC_003'], array_values(array_unique(array_column($story, 'sheet_id'))));
        $this->assertSame(0, $story[0]['cue_start']);
        $this->assertSame(count($cues) - 1, end($story)['cue_end']);
        foreach ($story as $s) {
            $hasKiki = collect($s['cast'])->contains('kind', 'kiki');
            $this->assertSame($s['sheet_id'] === 'SC_003', $hasKiki, "Kiki only where the sheet asks for him ({$s['sheet_id']}).");
        }
        $this->assertSame('bedroomNight', $story[0]['background']);
        $this->assertSame('sparse_piano', $story[0]['music']);
        $this->assertSame('You never owe anyone an explanation for protecting your peace.', end($scenes)['end_card']['advice']);
        for ($i = 1; $i < count($scenes); $i++) {
            $this->assertEqualsWithDelta($scenes[$i - 1]['end_time'], $scenes[$i]['start_time'], 0.001, 'Scenes are continuous.');
        }

        $director = app(TemplateSceneDirector::class);
        $endCardCode = $director->code(end($scenes), 5.0, count($scenes), $user);
        $this->assertStringContainsString('endCard(ctx, t', $endCardCode);
        $this->assertTrue(app(KikitoonsEngine::class)->validateCode($endCardCode)['ok']);
        $storyCode = $director->code($story[0], 3.0, 2, $user);
        $this->assertStringNotContainsString('"kind":"kiki"', $storyCode);
        $this->assertTrue(app(KikitoonsEngine::class)->validateCode($storyCode)['ok']);
    }

    public function test_estimated_srt_is_retimed_to_the_narration_and_old_kiki_tracks_are_ignored(): void
    {
        $user = $this->admin();
        $this->project = $this->landscapeProject($user);
        $oldIntro = $this->tone('Test_Story_KIKI_INTRO.wav', 5)->getRealPath();
        $package = app(PackageService::class)->createFromArchive($this->project, $user, $this->packageZip([$oldIntro]), $this->tone('narration.wav', 45));
        $before = app(SrtParser::class)->parse($package->srt_text);

        ValidateProductionPackageJob::dispatchSync($package->id);

        $package->refresh();
        $after = app(SrtParser::class)->parse($package->srt_text);
        $this->assertSame('narration.wav', $package->voiceover->original_name, 'A Kiki intro file in the ZIP is never used as the narration.');
        $this->assertSame('valid', $package->status);
        $this->assertSame(array_column($before, 'text'), array_column($after, 'text'), 'Subtitle words never change.');
        $this->assertLessThanOrEqual(45.01, end($after)['end']);
        $this->assertGreaterThan(end($before)['end'] + 10, end($after)['end'], 'Cues are spread over the real narration, not the estimate.');
        $this->assertArrayHasKey('srt_timing_adjustment', $package->validation_report);
    }

    protected function admin(): User
    {
        $this->seed(AccessControlSeeder::class);

        return User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);
    }

    protected function landscapeProject(User $user): Project
    {
        return Project::create([
            'user_id' => $user->id, 'title' => 'The Quiet Message', 'production_mode' => 'kikitoons', 'workflow' => 'approved_package',
            'aspect_ratio' => '16:9', 'width' => 1920, 'height' => 1080, 'frame_rate' => 30, 'generation_strategy' => 'template',
            'settings' => ['subtitles' => ['y' => 900]], 'status' => ProductionStatus::Draft,
        ]);
    }

    /** @param list<string> $extraFiles absolute paths added to the archive beside the fixture documents */
    protected function packageZip(array $extraFiles = []): UploadedFile
    {
        $path = $this->work.'/package.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach (File::files(base_path('tests/Fixtures/v2-package')) as $file) {
            $zip->addFile($file->getPathname(), 'Test_Story/'.$file->getFilename());
        }
        foreach ($extraFiles as $extraFile) {
            $zip->addFile($extraFile, 'Test_Story/'.basename($extraFile));
        }
        $zip->close();

        return new UploadedFile($path, 'Test_Story_Claude_Package.zip', 'application/zip', null, true);
    }

    /** Speech-like tone: 2.4 s sound, 0.6 s pause. */
    protected function tone(string $name, int $seconds): UploadedFile
    {
        $path = $this->work.'/'.$name;
        app(Ffmpeg::class)->mustRun([config('motionforge.ffmpeg'), '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', "sine=f=220:d={$seconds}", '-af', "volume='if(lt(mod(t,3),2.4),1,0)':eval=frame", $path]);

        return new UploadedFile($path, $name, 'audio/wav', null, true);
    }
}

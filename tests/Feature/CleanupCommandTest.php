<?php

namespace Tests\Feature;

use App\Models\Scene;
use App\Models\SceneAsset;
use App\Models\SceneVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MotionForgeFixtures;
use Tests\TestCase;

class CleanupCommandTest extends TestCase
{
    use MotionForgeFixtures, RefreshDatabase;

    public function test_cleanup_removes_only_stale_intermediates(): void
    {
        $this->seedRegistry();
        Storage::fake('local');
        $user = $this->userWithRole();
        $project = $this->project($user);
        $scene = Scene::create(['project_id' => $project->id, 'sequence' => 1, 'start_time' => 0, 'end_time' => 2, 'kind' => 'story', 'source' => 'canvas', 'status' => 'planned']);
        $old = SceneVersion::create(['scene_id' => $scene->id, 'version' => 1, 'plan' => [], 'origin' => 'template']);
        $cur = SceneVersion::create(['scene_id' => $scene->id, 'version' => 2, 'plan' => [], 'origin' => 'template']);
        $scene->update(['current_version_id' => $cur->id]);

        $stalePath = $project->storageDir('previews/old.png');
        $keepPath = $project->storageDir('previews/current.png');
        $finalPath = $project->storageDir('final/final.mp4');
        foreach ([$stalePath, $keepPath, $finalPath] as $p) Storage::disk('local')->put($p, 'x');
        $stale = SceneAsset::create(['scene_id' => $scene->id, 'scene_version_id' => $old->id, 'type' => 'preview_png', 'path' => $stalePath, 'origin' => 'render:test', 'status' => 'ready']);
        $keep = SceneAsset::create(['scene_id' => $scene->id, 'scene_version_id' => $cur->id, 'type' => 'preview_png', 'path' => $keepPath, 'origin' => 'render:test', 'status' => 'ready']);
        SceneAsset::whereKey([$stale->id, $keep->id])->update(['updated_at' => now()->subDays(40)]);

        $oldTmp = storage_path('app/tmp/render/test-old-'.uniqid());
        $newTmp = storage_path('app/tmp/render/test-new-'.uniqid());
        File::ensureDirectoryExists($oldTmp);
        File::ensureDirectoryExists($newTmp);
        touch($oldTmp, now()->subHours(12)->getTimestamp());

        try {
            $this->artisan('motionforge:cleanup', ['--dry-run' => true])->assertSuccessful();
            $this->assertDirectoryExists($oldTmp);
            $this->assertNotNull(SceneAsset::find($stale->id));

            $this->artisan('motionforge:cleanup')->assertSuccessful();

            $this->assertDirectoryDoesNotExist($oldTmp);
            $this->assertDirectoryExists($newTmp);
            $this->assertNull(SceneAsset::find($stale->id));
            Storage::disk('local')->assertMissing($stalePath);
            $this->assertNotNull(SceneAsset::find($keep->id));
            Storage::disk('local')->assertExists($keepPath);
            Storage::disk('local')->assertExists($finalPath);
        } finally {
            File::deleteDirectory($oldTmp);
            File::deleteDirectory($newTmp);
        }
    }
}

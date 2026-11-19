<?php

namespace Tests\Concerns;

use App\Enums\ProductionMode;
use App\Enums\ProductionStatus;
use App\Enums\ProviderStatus;
use App\Models\AiCredential;
use App\Models\AiProvider;
use App\Models\ProductionPackage;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\Production\PackageService;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\AiRegistrySeeder;

/**
 * Synthetic fixtures for feature tests. Every story line here is placeholder test text, not
 * production content, and lives only in the in-memory test database.
 */
trait MotionForgeFixtures
{
    protected function seedRegistry(): void
    {
        $this->seed([AccessControlSeeder::class, AiRegistrySeeder::class]);
    }

    protected function userWithRole(string $role = 'producer', array $attrs = []): User
    {
        return User::factory()->create($attrs + ['role_id' => Role::where('slug', $role)->value('id')]);
    }

    protected function project(User $owner, array $attrs = []): Project
    {
        return Project::create($attrs + [
            'user_id' => $owner->id, 'title' => 'Test Project', 'production_mode' => ProductionMode::Kikitoons,
            'workflow' => 'approved_package', 'aspect_ratio' => '9:16', 'width' => 1080, 'height' => 1920,
            'frame_rate' => 30, 'generation_strategy' => 'template', 'status' => ProductionStatus::Draft,
            'settings' => ['subtitles' => ['y' => 1344, 'style' => 'serious']],
        ]);
    }

    /** Six cues of neutral placeholder narration, 2.5 s each. */
    protected function srt(): string
    {
        $lines = self::LINES;
        $out = [];
        foreach ($lines as $i => $text) {
            $s = $i * 2.5;
            $out[] = ($i + 1)."\n".$this->ts($s).' --> '.$this->ts($s + 2.3)."\n".$text;
        }

        return implode("\n\n", $out)."\n";
    }

    protected const LINES = [
        'Test line one walked into the classroom.',
        'Test line two opened the door slowly.',
        'Test line three looked at the phone.',
        'Test line four was suddenly shocked!',
        'Test line five thought about the plan.',
        'Test line six went home at night.',
    ];

    private function ts(float $s): string
    {
        $ms = (int) round($s * 1000);

        return sprintf('%02d:%02d:%02d,%03d', intdiv($ms, 3600000), intdiv($ms % 3600000, 60000), intdiv($ms % 60000, 1000), $ms % 1000);
    }

    /** A validated, approved and hash-locked package (no voiceover file, so voice_hash is null). */
    protected function approvedPackage(Project $project, User $by): ProductionPackage
    {
        $package = $project->packages()->create([
            'version' => 1, 'status' => 'valid', 'source' => 'upload',
            'locked_story' => 'Synthetic test story.', 'narration_text' => implode(' ', self::LINES), 'srt_text' => $this->srt(),
            'validation_report' => ['status' => 'valid', 'checks' => [['key' => 'srt', 'status' => 'pass']], 'audio_duration' => 15.0, 'srt_end' => 14.8],
        ]);

        return app(PackageService::class)->approve($package, $by);
    }

    protected function credential(User $user, string $provider = 'kimi', string $key = 'sk-test-0123456789abcdef', ProviderStatus $status = ProviderStatus::Connected): AiCredential
    {
        return AiCredential::create([
            'user_id' => $user->id, 'ai_provider_id' => AiProvider::where('slug', $provider)->value('id'),
            'name' => ucfirst($provider).' test', 'secret' => $key, 'key_hint' => AiCredential::hintFor($key),
            'status' => $status, 'is_enabled' => true,
        ]);
    }
}

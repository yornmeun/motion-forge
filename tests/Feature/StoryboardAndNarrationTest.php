<?php

namespace Tests\Feature;

use App\Models\Scene;
use App\Services\Production\LockedNarrationException;
use App\Services\Production\NarrationMatcher;
use App\Services\Production\PackageService;
use App\Services\Production\SrtParser;
use App\Services\Production\StoryboardBuilder;
use App\Services\Production\StoryboardService;
use App\Services\Production\TemplateStoryboardPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Concerns\MotionForgeFixtures;
use Tests\TestCase;

class StoryboardAndNarrationTest extends TestCase
{
    use MotionForgeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRegistry();
        Storage::fake('local');
    }

    private function planned(): array
    {
        $user = $this->userWithRole();
        $project = $this->project($user);
        $this->approvedPackage($project, $user);
        $plan = app(TemplateStoryboardPlanner::class)->plan($project, $user);
        app(StoryboardService::class)->apply($project, $plan, $user);

        return [$user, $project->fresh(), $plan];
    }

    public function test_srt_parser_reads_cues_and_rejects_malformed_input(): void
    {
        $cues = app(SrtParser::class)->parse($this->srt());
        $this->assertCount(6, $cues);
        $this->assertSame(2.5, $cues[1]['start']);
        $this->assertSame(self::LINES[5], $cues[5]['text']);

        $this->expectException(InvalidArgumentException::class);
        app(SrtParser::class)->parse("1\n00:00:02,000 --> 00:00:01,000\nends before it starts");
    }

    public function test_template_storyboard_copies_narration_verbatim_and_validates(): void
    {
        [, $project, $plan] = $this->planned();
        $cues = app(SrtParser::class)->parse($project->approvedPackage->srt_text);

        $this->assertSame('template', $plan->origin);
        $this->assertSame([], app(StoryboardBuilder::class)->validate(app(TemplateStoryboardPlanner::class)->board($cues), $cues));

        $story = $project->scenes()->where('kind', 'story')->get();
        $this->assertNotEmpty($story);
        // every cue is covered exactly once, in order, with the SRT words unchanged
        $joined = $story->pluck('narration_reference')->implode(' ');
        $matcher = app(NarrationMatcher::class);
        $this->assertSame($matcher->words(implode(' ', self::LINES)), $matcher->words($joined));
        $this->assertSame(0, $story->first()->cue_start);
        $this->assertSame(5, $story->last()->cue_end);
        $this->assertSame('planned', $project->status->value);
    }

    public function test_changing_an_approved_input_blocks_production(): void
    {
        $user = $this->userWithRole();
        $project = $this->project($user);
        $package = $this->approvedPackage($project, $user);
        app(PackageService::class)->assertLocked($project); // passes while unchanged

        $package->update(['srt_text' => str_replace('Test line six', 'Edited line six', $package->srt_text)]);

        $this->expectException(LockedNarrationException::class);
        $this->expectExceptionMessage('srt_hash');
        app(PackageService::class)->assertLocked($project->fresh());
    }

    public function test_approval_requires_validation_and_acknowledged_warnings(): void
    {
        $user = $this->userWithRole();
        $project = $this->project($user);
        $draft = $project->packages()->create(['version' => 1, 'status' => 'draft', 'source' => 'upload', 'srt_text' => $this->srt()]);
        try {
            app(PackageService::class)->approve($draft, $user);
            $this->fail('A draft package must not be approvable.');
        } catch (\RuntimeException) {
        }

        $warned = $project->packages()->create(['version' => 2, 'status' => 'valid', 'source' => 'upload', 'srt_text' => $this->srt(), 'narration_text' => 'x', 'locked_story' => 'x', 'validation_report' => ['checks' => [['status' => 'warn']]]]);
        try {
            app(PackageService::class)->approve($warned, $user);
            $this->fail('Warnings must be acknowledged.');
        } catch (\RuntimeException) {
        }
        $this->assertSame('approved', app(PackageService::class)->approve($warned, $user, true)->status);
    }

    public function test_locked_narration_cannot_be_edited_in_the_service_or_over_http(): void
    {
        [$user, $project] = $this->planned();
        $scene = $project->scenes()->where('kind', 'story')->first();

        try {
            app(StoryboardService::class)->edit($scene, ['narration_reference' => 'Something else entirely.'], null, $user);
            $this->fail('Narration edits must be rejected.');
        } catch (LockedNarrationException) {
        }

        $this->actingAs($user)->patchJson("/projects/{$project->id}/scenes/{$scene->id}", ['plan' => ['narration_reference' => 'Rewritten narration.']])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Scene narration is locked to the approved SRT and cannot be edited.']);
        $this->assertSame(1, $scene->versions()->count());

        // a non-narration edit creates a new version and keeps the narration
        $this->actingAs($user)->patchJson("/projects/{$project->id}/scenes/{$scene->id}", ['plan' => ['emotion' => 'worried']])->assertOk();
        $this->assertSame('worried', $scene->fresh()->currentVersion->plan['emotion']);
        $this->assertSame($scene->narration_reference, $scene->fresh()->narration_reference);
    }

    public function test_caption_keywords_must_be_spoken_in_the_scene(): void
    {
        [$user, $project] = $this->planned();
        $scene = $project->scenes()->where('kind', 'story')->first();
        $this->actingAs($user)->patchJson("/projects/{$project->id}/scenes/{$scene->id}", ['plan' => ['caption_keywords' => ['zebra']]])->assertStatus(422);
    }

    public function test_reordering_story_scenes_out_of_narration_order_is_rejected(): void
    {
        [$user, $project] = $this->planned();
        $ids = $project->scenes()->pluck('id')->all();
        $this->assertGreaterThan(2, count($ids));
        $story = $project->scenes()->where('kind', 'story')->pluck('id')->all();
        $swapped = $ids;
        [$i, $j] = [array_search($story[0], $ids), array_search($story[1], $ids)];
        [$swapped[$i], $swapped[$j]] = [$swapped[$j], $swapped[$i]];

        $this->actingAs($user)->postJson("/projects/{$project->id}/scenes/order", ['order' => $swapped])->assertStatus(422);
        $this->actingAs($user)->postJson("/projects/{$project->id}/scenes/order", ['order' => $ids])->assertOk();
        $this->actingAs($user)->postJson("/projects/{$project->id}/scenes/order", ['order' => array_slice($ids, 1)])->assertStatus(422);
    }

    public function test_editor_state_player_sandbox_and_undo_redo(): void
    {
        [$user, $project] = $this->planned();
        $scene = $project->scenes()->where('kind', 'story')->first();
        $stranger = $this->userWithRole();

        $state = $this->actingAs($user)->getJson("/projects/{$project->id}/editor/state")->assertOk()->json();
        $this->assertCount($project->scenes()->count(), $state['scenes']);
        $this->assertSame($scene->narration_reference, $state['scenes'][0]['narration']);
        $this->assertCount(6, $state['cues']);
        $this->actingAs($stranger)->getJson("/projects/{$project->id}/editor/state")->assertForbidden();

        // the live preview is served with a sandbox CSP (opaque origin, no network)
        $player = $this->actingAs($user)->get("/projects/{$project->id}/scenes/{$scene->id}/player")->assertOk();
        $this->assertStringContainsString('sandbox allow-scripts', $player->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("default-src 'none'", $player->headers->get('Content-Security-Policy'));

        // edit → v2, undo → v1, redo → v2
        $v1 = $scene->current_version_id;
        $this->actingAs($user)->patchJson("/projects/{$project->id}/scenes/{$scene->id}", ['plan' => ['emotion' => 'sad']])->assertOk();
        $v2 = $scene->fresh()->current_version_id;
        $this->assertNotSame($v1, $v2);
        $this->actingAs($user)->postJson("/projects/{$project->id}/scenes/{$scene->id}/revert", ['version_id' => $v1])->assertOk();
        $this->assertSame($v1, $scene->fresh()->current_version_id);
        $this->actingAs($user)->postJson("/projects/{$project->id}/scenes/{$scene->id}/revert", ['version_id' => $v2])->assertOk();
        $this->assertSame('sad', $scene->fresh()->currentVersion->plan['emotion']);
    }

    public function test_scene_boundary_moves_keep_every_cue(): void
    {
        [$user, $project] = $this->planned();
        $story = $project->scenes()->where('kind', 'story')->get()->values();
        if ($story->count() < 2) {
            $this->markTestSkipped('The template planner produced a single story scene for this fixture.');
        }
        $first = $story[0];
        $second = $story[1];
        $newEnd = $first->cue_end < $second->cue_end - 1 ? $first->cue_end + 1 : $first->cue_end - 1;
        if ($newEnd < $first->cue_start) {
            $this->markTestSkipped('No legal boundary move for this fixture.');
        }
        app(StoryboardService::class)->moveBoundary($first, $newEnd, $user);

        [$a, $b] = [Scene::find($first->id), Scene::find($second->id)];
        $this->assertSame($newEnd, $a->cue_end);
        $this->assertSame($newEnd + 1, $b->cue_start);
        $this->assertEqualsWithDelta($a->end_time, $b->start_time, 0.0001);
    }
}

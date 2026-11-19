<?php

namespace App\Services\Production;

use App\Contracts\ScenePlannerInterface;
use App\Models\ProductionPackage;
use App\Models\Project;
use App\Models\User;
use App\Services\Production\Data\PlanResult;
use RuntimeException;

/**
 * Template planner for V2 packages (no AI, no cost). SCENE_SHEET.md is the plan: every sheet scene
 * keeps its ID, cue range, location, characters and transition, and is staged as a few shots inside
 * that range. Kiki appears only in the end card and in scenes whose sheet entry asks for him. Narration and timing still come only from the locked SRT and voice.
 */
class SceneSheetPlanner implements ScenePlannerInterface
{
    public function __construct(
        private PackageService $packages,
        private SrtParser $srt,
        private StoryboardBuilder $builder,
        private SceneSheetParser $sheets,
        private PackageAssetMapper $assets,
        private TemplateStoryboardPlanner $template,
        private NarrationMatcher $matcher,
        private ActionLexicon $lexicon,
    ) {}

    public function plan(Project $project, User $user, array $options = []): PlanResult
    {
        $package = $this->packages->assertLocked($project);
        $cues = $this->srt->parse($package->srt_text);
        $sheet = $this->sheets->scenes((string) $package->document('scene_sheet'));
        if (! $sheet) {
            throw new RuntimeException('The scene sheet has no scene entries.');
        }
        $characters = $this->assets->characters($this->sheets->registry((string) $package->document('character_registry'), 'CHAR'));
        $locations = collect($this->sheets->registry((string) $package->document('background_registry'), 'BG'))->map(fn (array $entry) => $this->assets->background($entry))->all();

        $firstCues = $this->firstCues($sheet, $cues);
        $board = $this->template->board($cues, $package->notes, array_values(array_filter($firstCues, fn (int $cue) => $cue > 0)));
        $extras = [];
        $previousBackground = null;
        $owner = -1;
        foreach ($board['scenes'] as $k => &$shot) {
            $index = $this->ownerOf($firstCues, $shot['cue_start']);
            $entry = $sheet[$index];
            $opensScene = $index !== $owner;
            $owner = $index;
            $location = $locations[$entry['background']] ?? null;
            $text = implode(' ', array_column(array_slice($cues, $shot['cue_start'], $shot['cue_end'] - $shot['cue_start'] + 1), 'text'));
            $cast = $this->cast($entry, $characters, $text);

            $shot['background'] = $location['background'] ?? $shot['background'];
            $shot['lighting'] = $location['look'] ?? $shot['lighting'];
            $shot['music'] = $this->music($entry) ?? $shot['music'];
            $shot['characters'] = array_column($cast, 'name');
            $shot['camera'] = $this->camera($entry, $shot['camera'], $opensScene);
            $shot['character_actions'] = $this->lexicon->phrases($text)
                ?: ($this->lexicon->phrases($this->sheets->field($entry['fields'], ['action', 'blocking'])) ?: $shot['character_actions']);
            $shot['transition'] = $k === 0 ? 'none' : ($opensScene ? $this->transition($entry, $previousBackground !== $shot['background']) : 'none');
            $shot['visual_description'] = mb_substr("{$entry['id']} — {$entry['title']}: ".($this->sheets->field($entry['fields'], ['action', 'blocking']) ?: $shot['visual_description']), 0, 600);
            $previousBackground = $shot['background'];
            $extras[$k] = ['sheet_id' => $entry['id'], 'sheet_title' => $entry['title'], 'cast' => $cast, 'kiki' => $entry['kiki'],
                'direction' => array_filter(['camera' => $this->sheets->field($entry['fields'], ['camera movement']), 'sound' => $this->sheets->field($entry['fields'], ['sfx', 'sound']), 'music' => $this->sheets->field($entry['fields'], ['music'])])];
        }
        unset($shot);
        $this->varyShots($board['scenes']);

        $errors = $this->builder->validate($board, $cues);
        if ($errors) {
            throw new RuntimeException('Scene sheet storyboard failed validation: '.implode('; ', array_slice($errors, 0, 3)));
        }
        $scenes = $this->builder->build($board, $cues, $package);
        array_pop($scenes); // the builder's end card is replaced by the V2 one (package moral or no advice)
        foreach ($scenes as $k => &$scene) {
            $scene = array_merge($scene, $extras[$k]);
        }
        unset($scene);

        return new PlanResult($this->timeline($scenes, $package), ['slam_word' => $board['slam_word'], 'sheet_scenes' => count($sheet)], 'template:scene-sheet');
    }

    /**
     * 0-based index of the first cue of each sheet scene. Sheet ranges are 1-based and may share their
     * boundary cue ("1–14", "14–38"), so each scene starts at its own first cue and ends before the next.
     *
     * @return list<int>
     */
    public function firstCues(array $sheet, array $cues): array
    {
        $count = count($cues);
        $starts = [];
        foreach ($sheet as $i => $entry) {
            if ($entry['cue_start'] !== null) {
                $cue = $entry['cue_start'] - 1;
            } elseif ($entry['start'] !== null) {
                $offset = $cues[0]['start'] - ($sheet[0]['start'] ?? 0);
                $cue = collect($cues)->search(fn (array $c) => $c['start'] >= $entry['start'] + $offset - 0.05);
                $cue = $cue === false ? $count - 1 : $cue;
            } else {
                $cue = (int) floor($i * $count / count($sheet));
            }
            $previous = $starts ? end($starts) : -1;
            $starts[] = $i === 0 ? 0 : min($count - 1, max($previous + 1, $cue));
        }

        return $starts;
    }

    private function ownerOf(array $firstCues, int $cue): int
    {
        $owner = 0;
        foreach ($firstCues as $i => $first) {
            if ($cue >= $first) {
                $owner = $i;
            }
        }

        return $owner;
    }

    /**
     * Registry characters for a shot. Characters the sheet marks as conditional ("only where the scene
     * includes Vicheka") appear only when the shot's narration names them.
     *
     * @return list<array{kind: string, name: string, def?: array}>
     */
    private function cast(array $entry, array $characters, string $text): array
    {
        $spoken = $this->matcher->words($text);
        $cast = [];
        foreach ($entry['characters'] as $character) {
            $definition = $characters[$character['id']] ?? null;
            if (! $definition) {
                continue;
            }
            $named = in_array(mb_strtolower($definition['name']), $spoken, true);
            if (! $character['conditional'] || $named) {
                $cast[] = $definition;
            }
        }
        if (! $cast && $entry['characters'] && isset($characters[$entry['characters'][0]['id']])) {
            $cast[] = $characters[$entry['characters'][0]['id']];
        }
        $cast = array_slice($cast, 0, $entry['kiki'] ? 2 : 3);
        if ($entry['kiki']) {
            $cast[] = ['kind' => 'kiki', 'name' => 'Kiki'];
        }

        return $cast;
    }

    /** First shot of a sheet scene follows its shot direction (establishing → wide); the move follows its camera notes. */
    private function camera(array $entry, array $camera, bool $opensScene): array
    {
        $shot = mb_strtolower($this->sheets->field($entry['fields'], ['shot', 'angle']));
        $move = mb_strtolower($this->sheets->field($entry['fields'], ['camera movement', 'movement']));
        if ($opensScene && preg_match('/establish|wide/u', $shot)) {
            $camera['shot'] = 'wide';
        }
        $moves = array_values(array_filter([
            preg_match('/push/u', $move) ? 'slow_push_in' : null,
            preg_match('/locked|static|hold/u', $move) ? 'static_drift' : null,
            preg_match('/pull/u', $move) ? 'slow_pull_out' : null,
            preg_match('/\bpan\b/u', $move) ? 'pan_right' : null,
            preg_match('/track|follow/u', $move) ? 'track' : null,
            preg_match('/handheld/u', $move) ? 'handheld_drift' : null,
        ]));
        if ($moves && ! in_array($camera['movement'], $moves, true)) {
            $camera['movement'] = $moves[crc32($entry['id'].$camera['shot']) % count($moves)];
        }

        return $camera;
    }

    /** Score mood named by the sheet's music direction, if any. */
    private function music(array $entry): ?string
    {
        $text = mb_strtolower($this->sheets->field($entry['fields'], ['music']));

        return match (true) {
            str_contains($text, 'piano') => 'sparse_piano',
            str_contains($text, 'string') => 'tense_strings',
            (bool) preg_match('/synth|pulse/u', $text) => 'pulse_synth',
            (bool) preg_match('/swell|epic|build/u', $text) => 'epic_swell',
            (bool) preg_match('/drone|ambient/u', $text) => 'dark_drone',
            default => null,
        };
    }

    private function transition(array $entry, bool $locationChanges): string
    {
        $text = mb_strtolower($this->sheets->field($entry['fields'], ['transition']));

        return match (true) {
            str_contains($text, 'whip') => 'whip',
            str_contains($text, 'smash') => 'smash',
            str_contains($text, 'match') => 'match',
            str_contains($text, 'zoom') => 'zoom',
            (bool) preg_match('/dissolve|fade|dip/u', $text) && $locationChanges => 'dip',
            default => 'none',
        };
    }

    /** No two consecutive shots share an angle (house style; also required by the storyboard validator). */
    private function varyShots(array &$shots): void
    {
        foreach ($shots as $k => &$shot) {
            $previous = $shots[$k - 1]['camera']['shot'] ?? null;
            if ($shot['camera']['shot'] === $previous) {
                $next = $shots[$k + 1]['camera']['shot'] ?? null;
                $shot['camera']['shot'] = collect(['medium', 'close_up', 'wide', 'high_angle'])->first(fn (string $s) => $s !== $previous && $s !== $next);
            }
        }
    }

    /** Story scenes (timed by the builder from the SRT and voice) followed by the subscribe end card. */
    private function timeline(array $scenes, ProductionPackage $package): array
    {
        $last = $scenes[count($scenes) - 1];
        $end = $last['end_time'];
        $endCardSeconds = (float) config('motionforge.defaults.end_card_seconds', 5);
        $scenes[] = [
            'scene_number' => count($scenes) + 1, 'kind' => 'end_card', 'start_time' => $end, 'end_time' => round($end + $endCardSeconds, 3),
            'cue_start' => null, 'cue_end' => null, 'narration_reference' => '',
            'visual_description' => 'End card: Kiki on the right; advice from the package (if it gives one) and SUBSCRIBE OR FOLLOW FOR MORE VIDEOS.',
            'characters' => ['Kiki'], 'character_actions' => ['small confident nod to camera'], 'emotion' => 'confident',
            'camera' => ['shot' => 'medium', 'movement' => 'slow_push_in'], 'lighting' => 'payoff',
            'background' => $last['background'], 'sound_effects' => ['low_hit'], 'music' => 'epic_swell',
            'ambience' => $last['ambience'], 'transition' => 'dip', 'caption_keywords' => [], 'slam_word' => null,
            'end_card' => ['advice' => $this->advice($package), 'accent' => ''],
        ];

        return $scenes;
    }

    /** The package's own moral, if it states one; otherwise none (never invented). */
    public function advice(ProductionPackage $package): string
    {
        $sources = [$package->document('kiki_reference'), $package->locked_story, $package->document('video_execution'), $package->notes];
        foreach (array_filter($sources) as $text) {
            if (preg_match('/^\s*(?:[-*#>]+\s*)?\**\s*(?:moral|lesson|takeaway|advice|final message|message)\s*\**\s*:\s*\**\s*(.{10,120}?)\s*\**\s*$/mi', $text, $m)) {
                return trim($m[1], ' "“”*');
            }
        }

        return '';
    }
}

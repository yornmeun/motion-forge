<?php

namespace App\Services\Production;

use App\Contracts\ScenePlannerInterface;
use App\Models\Project;
use App\Models\User;
use App\Services\Production\Data\PlanResult;

/**
 * Deterministic planner that needs no AI provider ("template" strategy). It groups SRT cues into
 * 3–8 s scenes and picks backgrounds, shots and sound by simple keyword rules. Its output is
 * labelled as template-generated everywhere it appears; it is a working baseline, not AI direction.
 */
class TemplateStoryboardPlanner implements ScenePlannerInterface
{
    public function __construct(private PackageService $packages, private SrtParser $srt, private StoryboardBuilder $builder, private NarrationMatcher $matcher) {}

    public function plan(Project $project, User $user, array $options = []): PlanResult
    {
        $package = $this->packages->assertLocked($project);
        $cues = $this->srt->parse($package->srt_text);
        $board = $this->board($cues, $package->notes);
        $errors = $this->builder->validate($board, $cues);
        if ($errors) {
            throw new \RuntimeException('Template storyboard failed validation: '.implode('; ', array_slice($errors, 0, 3)));
        }

        return new PlanResult($this->builder->build($board, $cues, $package), ['slam_word' => $board['slam_word']], 'template');
    }

    public function board(array $cues, ?string $notes = null): array
    {
        $groups = [];
        $cur = null;
        foreach ($cues as $i => $c) {
            if ($cur === null) { $cur = ['cue_start' => $i, 'cue_end' => $i, 'start' => $c['start'], 'end' => $c['end'], 'text' => $c['text']]; continue; }
            $len = $c['end'] - $cur['start'];
            $sentenceEnd = (bool) preg_match('/[.!?…]["”]?$/u', trim($cur['text']));
            if ($len > 8 || ($cur['end'] - $cur['start'] >= 3.2 && $sentenceEnd) || $cur['end'] - $cur['start'] >= 6) {
                $groups[] = $cur;
                $cur = ['cue_start' => $i, 'cue_end' => $i, 'start' => $c['start'], 'end' => $c['end'], 'text' => $c['text']];
            } else {
                $cur['cue_end'] = $i; $cur['end'] = $c['end']; $cur['text'] .= ' '.$c['text'];
            }
        }
        if ($cur) $groups[] = $cur;

        $shots = ['medium', 'close_up', 'wide', 'over_the_shoulder', 'medium', 'low_angle', 'close_up', 'high_angle', 'extreme_close_up', 'wide'];
        $moves = ['slow_push_in', 'handheld_drift', 'slow_pull_out', 'slow_push_in', 'pan_right', 'static_drift', 'slow_push_in', 'pan_left'];
        $n = count($groups);
        $bg = 'hallway';
        $scenes = [];
        $prevShot = null;
        foreach ($groups as $k => $g) {
            $text = mb_strtolower($g['text']);
            $bg = $this->background($text) ?? $bg;
            $phase = $n > 1 ? $k / ($n - 1) : 0;
            $emotion = $this->emotion($text, $phase);
            $shot = $shots[$k % count($shots)];
            if ($emotion === 'shocked' || $emotion === 'afraid') $shot = 'close_up';
            if ($shot === $prevShot) $shot = $shot === 'close_up' ? 'medium' : 'close_up';
            $prevShot = $shot;
            $look = $phase < 0.35 ? 'neutral' : ($phase < 0.7 ? ($emotion === 'sad' ? 'cold' : 'tense') : ($phase > 0.85 ? 'payoff' : 'tense'));
            if (str_contains($bg, 'Night')) $look = 'night';
            $sfx = [];
            if ($k === 0) $sfx[] = 'boom';
            if (preg_match('/\b(walk|walked|ran|run|went|came|left)\b/u', $text)) $sfx[] = 'footsteps';
            if (preg_match('/\b(door|opened|closed|slammed)\b/u', $text)) $sfx[] = 'door';
            if (preg_match('/\b(phone|text|message|click|typed|password)\b/u', $text)) $sfx[] = 'click';
            if (preg_match('/\b(paper|letter|note|grade|test)\b/u', $text)) $sfx[] = 'paper';
            if ($emotion === 'shocked') $sfx[] = 'hit_metal';
            if ($emotion === 'afraid' || $emotion === 'worried') $sfx[] = 'heartbeat';
            if (! $sfx) $sfx[] = $k % 2 ? 'whoosh' : 'low_hit';
            $music = $phase < 0.3 ? 'dark_drone' : ($emotion === 'sad' ? 'sparse_piano' : ($phase < 0.8 ? 'pulse_synth' : 'epic_swell'));
            $keywords = $this->keywords($g['text']);
            $actions = $this->actions($text);
            $characters = ['Kiki'];
            if (preg_match('/\bprincipal\b/u', $text)) $characters[] = 'Principal Karl';
            elseif (preg_match('/\b(teacher|boss|dad|mom|mother|father|man|woman|officer|doctor|wife|husband)\b/u', $text)) $characters[] = 'Adult';
            elseif (preg_match('/\b(friend|classmate|kid|boy|girl|brother|sister)\b/u', $text)) $characters[] = 'Kid';
            $scenes[] = [
                'cue_start' => $g['cue_start'], 'cue_end' => $g['cue_end'],
                'visual_description' => 'Template staging: '.implode(', ', $characters)." in the {$bg}, {$emotion} beat for: \"".mb_substr($g['text'], 0, 120).'"',
                'characters' => $characters, 'character_actions' => $actions, 'emotion' => $emotion,
                'camera' => ['shot' => $shot, 'movement' => $moves[$k % count($moves)]],
                'lighting' => $look, 'background' => $bg, 'sound_effects' => array_slice(array_values(array_unique($sfx)), 0, 4),
                'music' => $music, 'transition' => $k === 0 ? 'none' : ($this->background($text) && $k > 0 ? 'dip' : ($emotion === 'shocked' ? 'smash' : 'none')),
                'caption_keywords' => $keywords,
            ];
        }
        $slamGroup = $groups[(int) floor(($n - 1) * 0.75)] ?? end($groups);
        $slam = collect($this->matcher->words($slamGroup['text']))->filter(fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, self::STOP, true))->sortByDesc(fn ($w) => mb_strlen($w))->first()
            ?? $this->matcher->words($slamGroup['text'])[0];
        $advice = null;
        if ($notes && preg_match('/^\s*(advice|lesson|moral)\s*:\s*(.{10,110})$/mi', $notes, $m)) $advice = trim($m[2]);

        return [
            'scenes' => $scenes, 'slam_word' => $slam,
            'end_card_advice' => $advice ?? 'Edit this advice line before the final render.',
            'end_card_accent_word' => '',
        ];
    }

    private const STOP = ['that', 'this', 'with', 'what', 'when', 'then', 'they', 'there', 'their', 'have', 'from', 'were', 'would', 'could', 'about', 'just', 'like', 'into', 'your', 'because', 'didn\'t', 'wasn\'t', 'it\'s', 'i\'m'];

    private function keywords(string $text): array
    {
        $words = collect($this->matcher->words($text))->filter(fn ($w) => mb_strlen($w) >= 5 && ! in_array($w, self::STOP, true));
        $num = collect($this->matcher->words($text))->first(fn ($w) => preg_match('/^\d+$/', $w));

        return array_values(array_slice(array_filter([$num, $words->sortByDesc(fn ($w) => mb_strlen($w))->first()]), 0, 1));
    }

    private function background(string $t): ?string
    {
        return match (true) {
            (bool) preg_match('/\b(principal|office|boss)\b/u', $t) => 'office',
            (bool) preg_match('/\b(class|classroom|teacher|test|homework|grade)\b/u', $t) => 'classroom',
            (bool) preg_match('/\b(hallway|locker|school)\b/u', $t) => 'hallway',
            (bool) preg_match('/\b(wifi|server|computer|network|hack)\b/u', $t) => 'serverRoom',
            (bool) preg_match('/\b(night|bed|dark|sleep|bedroom)\b/u', $t) => 'bedroomNight',
            (bool) preg_match('/\b(kitchen|dinner|breakfast|mom|dad)\b/u', $t) => 'kitchen',
            (bool) preg_match('/\b(street|road|car|outside|walked home)\b/u', $t) => 'street',
            (bool) preg_match('/\b(park|tree|playground)\b/u', $t) => 'park',
            (bool) preg_match('/\b(shop|store|stand|buy|sell|money|dollar)\b/u', $t) => 'shop',
            default => null,
        };
    }

    private function emotion(string $t, float $phase): string
    {
        return match (true) {
            (bool) preg_match('/\b(scared|afraid|terrified|fear)\b/u', $t) => 'afraid',
            (bool) preg_match('/\b(shocked|froze|suddenly|couldn\'t believe|gasp)\b/u', $t) || str_contains($t, '!') => 'shocked',
            (bool) preg_match('/\b(sad|cried|tears|alone|lost)\b/u', $t) => 'sad',
            (bool) preg_match('/\b(angry|furious|yelled|mad)\b/u', $t) => 'angry',
            (bool) preg_match('/\b(worried|nervous|wrong)\b/u', $t) => 'worried',
            (bool) preg_match('/\b(plan|secret|trick|smirk)\b/u', $t) => 'sneaky',
            $phase > 0.85 => 'confident',
            default => $phase < 0.2 ? 'determined' : 'neutral',
        };
    }

    private function actions(string $t): array
    {
        $a = [];
        if (preg_match('/\b(walk|walked|went|came)\b/u', $t)) $a[] = 'walks forward';
        if (preg_match('/\b(ran|run|running)\b/u', $t)) $a[] = 'runs';
        if (preg_match('/\b(phone|text|message|typed)\b/u', $t)) $a[] = 'looks at phone';
        if (preg_match('/\b(pointed|accused|you)\b/u', $t)) $a[] = 'points';
        if (preg_match('/\b(turned|looked)\b/u', $t)) $a[] = 'slowly turns head';
        if (preg_match('/\b(think|thought|realized|wondered)\b/u', $t)) $a[] = 'thinks';

        return $a ?: ['holds a tense stare, breathing'];
    }
}

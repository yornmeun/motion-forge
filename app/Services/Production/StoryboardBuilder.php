<?php

namespace App\Services\Production;

use App\Models\ProductionPackage;
use App\Services\AI\AIResponseValidator;

/**
 * Turns a cue-range storyboard (from the AI or the template planner) into timed scene plans,
 * and validates it. Times come only from the SRT/voice; narration text is copied verbatim from
 * the locked SRT cues. Nothing here can alter narration.
 */
class StoryboardBuilder
{
    public const LEAD = 0.15;       // cut slightly before the first word of a scene
    public const FINAL_HOLD = 1.2;  // hold the last story frame

    public function __construct(private NarrationMatcher $matcher, private AIResponseValidator $schemaValidator) {}

    /** @return list<string> errors (empty = valid) */
    public function validate(array $board, array $cues): array
    {
        $errors = $this->schemaValidator->validate($board, PlanVocabulary::storyboardSchema(count($cues)));
        if ($errors) {
            return $errors;
        }
        $expected = 0;
        $prevShot = null;
        foreach ($board['scenes'] as $i => $s) {
            $n = $i + 1;
            if ($s['cue_start'] !== $expected) {
                $errors[] = "scene {$n}: cue_start must be {$expected} (scenes must cover every cue in order, without gaps or overlaps)";
            }
            if ($s['cue_end'] < $s['cue_start']) {
                $errors[] = "scene {$n}: cue_end is before cue_start";
            }
            $expected = $s['cue_end'] + 1;
            $shot = $s['camera']['shot'];
            if ($shot === $prevShot) {
                $errors[] = "scene {$n}: same shot type ({$shot}) as the previous scene; vary the angle";
            }
            $prevShot = $shot;
            $text = implode(' ', array_column(array_slice($cues, $s['cue_start'], max(0, $s['cue_end'] - $s['cue_start'] + 1)), 'text'));
            $words = $this->matcher->words($text);
            foreach ($s['caption_keywords'] as $kw) {
                if (! in_array(mb_strtolower(trim($kw, " *!.,")), $words, true)) {
                    $errors[] = "scene {$n}: caption keyword \"{$kw}\" is not spoken in this scene";
                }
            }
        }
        if ($expected !== count($cues)) {
            $errors[] = 'the scenes must end at the last cue ('.(count($cues) - 1).'), got '.($expected - 1);
        }
        $all = $this->matcher->words(implode(' ', array_column($cues, 'text')));
        if (! in_array(mb_strtolower(trim($board['slam_word'], " *!.,")), $all, true)) {
            $errors[] = "slam_word \"{$board['slam_word']}\" is not a spoken word";
        }

        return $errors;
    }

    /** Build timed scene plans + end card. */
    public function build(array $board, array $cues, ProductionPackage $package, array $defaults = []): array
    {
        $audioEnd = max((float) $package->audio_duration, (float) end($cues)['end']);
        $scenes = [];
        $count = count($board['scenes']);
        $slam = mb_strtolower(trim($board['slam_word'], " *!.,"));
        $slamUsed = false;
        foreach ($board['scenes'] as $i => $s) {
            $first = $cues[$s['cue_start']];
            $start = $i === 0 ? 0.0 : max($scenes[$i - 1]['start_time'] + 0.6, round($first['start'] - self::LEAD, 3));
            if ($i > 0) {
                $scenes[$i - 1]['end_time'] = $start;
            }
            $slice = array_slice($cues, $s['cue_start'], $s['cue_end'] - $s['cue_start'] + 1);
            $narration = implode(' ', array_column($slice, 'text'));
            $hasSlam = ! $slamUsed && in_array($slam, $this->matcher->words($narration), true);
            $slamUsed = $slamUsed || $hasSlam;
            $scenes[] = [
                'scene_number' => $i + 1, 'kind' => 'story',
                'start_time' => round($start, 3), 'end_time' => null,
                'cue_start' => $s['cue_start'], 'cue_end' => $s['cue_end'],
                'narration_reference' => $narration,
                'visual_description' => $s['visual_description'],
                'characters' => array_values($s['characters']),
                'character_actions' => array_values($s['character_actions']),
                'emotion' => $s['emotion'],
                'camera' => $s['camera'],
                'lighting' => $s['lighting'],
                'background' => $s['background'],
                'sound_effects' => array_values($s['sound_effects']),
                'music' => $s['music'],
                'ambience' => PlanVocabulary::AMBIENCE[$s['background']] ?? 'room_tone',
                'transition' => $i === 0 ? 'none' : $s['transition'],
                'caption_keywords' => array_values(array_map(fn ($k) => mb_strtolower(trim($k, ' *!.,')), $s['caption_keywords'])),
                'slam_word' => $hasSlam ? $slam : null,
            ];
        }
        $scenes[$count - 1]['end_time'] = round($audioEnd + self::FINAL_HOLD, 3);
        $endDur = (float) ($defaults['end_card_seconds'] ?? config('motionforge.defaults.end_card_seconds', 4.5));
        $last = $scenes[$count - 1];
        $scenes[] = [
            'scene_number' => $count + 1, 'kind' => 'end_card',
            'start_time' => $last['end_time'], 'end_time' => round($last['end_time'] + $endDur, 3),
            'cue_start' => null, 'cue_end' => null, 'narration_reference' => '',
            'visual_description' => 'End card: advice line and FOLLOW FOR MORE VIDEOS over a slow push-in on Kiki.',
            'characters' => ['Kiki'], 'character_actions' => ['small confident nod to camera'], 'emotion' => 'confident',
            'camera' => ['shot' => 'medium', 'movement' => 'slow_push_in'], 'lighting' => 'payoff',
            'background' => $last['background'], 'sound_effects' => ['low_hit'], 'music' => 'epic_swell',
            'ambience' => $last['ambience'], 'transition' => 'dip', 'caption_keywords' => [], 'slam_word' => null,
            'end_card' => ['advice' => $board['end_card_advice'], 'accent' => $board['end_card_accent_word'] ?? ''],
        ];

        return $scenes;
    }
}

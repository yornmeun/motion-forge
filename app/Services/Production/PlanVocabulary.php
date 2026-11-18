<?php

namespace App\Services\Production;

/** Closed vocabularies shared by the AI schema, the validator, the template director and the editor. */
final class PlanVocabulary
{
    public const SHOTS = ['wide', 'medium', 'close_up', 'extreme_close_up', 'over_the_shoulder', 'pov', 'low_angle', 'high_angle'];
    public const MOVES = ['slow_push_in', 'slow_pull_out', 'pan_left', 'pan_right', 'track', 'dolly_zoom', 'handheld_drift', 'static_drift'];
    public const LOOKS = ['neutral', 'warm', 'cold', 'night', 'tense', 'payoff'];
    public const EMOTIONS = ['neutral', 'determined', 'worried', 'shocked', 'sad', 'angry', 'sneaky', 'confident', 'relieved', 'afraid'];
    public const TRANSITIONS = ['none', 'whip', 'zoom', 'dip', 'smash', 'match'];
    public const SFX = ['boom', 'subdrop', 'low_hit', 'braam', 'riser', 'reverse_swell', 'whoosh', 'hit_metal', 'tick', 'click', 'heartbeat', 'breath', 'footsteps', 'door', 'paper', 'button'];
    public const MUSIC = ['dark_drone', 'tense_strings', 'sparse_piano', 'pulse_synth', 'epic_swell', 'silence'];
    public const BACKGROUNDS = ['hallway', 'classroom', 'office', 'serverRoom', 'street', 'streetNight', 'kitchen', 'bedroom', 'bedroomNight', 'park', 'shop'];
    public const AMBIENCE = ['hallway' => 'hallway', 'classroom' => 'room_tone', 'office' => 'office', 'serverRoom' => 'server', 'street' => 'outdoors', 'streetNight' => 'night', 'kitchen' => 'room_tone', 'bedroom' => 'room_tone', 'bedroomNight' => 'night', 'park' => 'outdoors', 'shop' => 'room_tone'];

    /** JSON schema for AI storyboard output (strict-mode compatible: all keys required, no extras). */
    public static function storyboardSchema(int $cueCount): array
    {
        $str = ['type' => 'string'];
        $enum = fn (array $v) => ['type' => 'string', 'enum' => $v];

        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['scenes', 'slam_word', 'end_card_advice', 'end_card_accent_word'],
            'properties' => [
                'scenes' => ['type' => 'array', 'minItems' => 1, 'maxItems' => max(1, $cueCount), 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['cue_start', 'cue_end', 'visual_description', 'characters', 'character_actions', 'emotion', 'camera', 'lighting', 'background', 'sound_effects', 'music', 'transition', 'caption_keywords'],
                    'properties' => [
                        'cue_start' => ['type' => 'integer', 'minimum' => 0, 'maximum' => $cueCount - 1],
                        'cue_end' => ['type' => 'integer', 'minimum' => 0, 'maximum' => $cueCount - 1],
                        'visual_description' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 600],
                        'characters' => ['type' => 'array', 'items' => $str, 'maxItems' => 3],
                        'character_actions' => ['type' => 'array', 'items' => $str, 'maxItems' => 4],
                        'emotion' => $enum(self::EMOTIONS),
                        'camera' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['shot', 'movement'], 'properties' => ['shot' => $enum(self::SHOTS), 'movement' => $enum(self::MOVES)]],
                        'lighting' => $enum(self::LOOKS),
                        'background' => $enum(self::BACKGROUNDS),
                        'sound_effects' => ['type' => 'array', 'items' => $enum(self::SFX), 'maxItems' => 4],
                        'music' => $enum(self::MUSIC),
                        'transition' => $enum(self::TRANSITIONS),
                        'caption_keywords' => ['type' => 'array', 'items' => $str, 'maxItems' => 2],
                    ],
                ]],
                'slam_word' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 30],
                'end_card_advice' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 120],
                'end_card_accent_word' => ['type' => 'string', 'maxLength' => 30],
            ],
        ];
    }
}

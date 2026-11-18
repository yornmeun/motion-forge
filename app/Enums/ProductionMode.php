<?php

namespace App\Enums;

enum ProductionMode: string
{
    case Kikitoons = 'kikitoons';
    case NativeVideo = 'native_video';
    case Hybrid = 'hybrid';

    public function label(): string
    {
        return match ($this) {
            self::Kikitoons => 'Kikitoons 2D Animation',
            self::NativeVideo => 'Native AI Video',
            self::Hybrid => 'Hybrid AI Production',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Kikitoons => 'Approved story, narration, SRT and voiceover animated by the Canvas engine. AI writes scene plans and animation code.',
            self::NativeVideo => 'Clips from a model with verified video generation, approved per scene and assembled with FFmpeg.',
            self::Hybrid => 'Canvas animation per scene, with optional AI video clips for chosen scenes, plus voice, subtitles and mix.',
        };
    }

    /** Modes whose production is driven by a locked narration + voiceover package. */
    public function requiresLockedPackage(): bool
    {
        return $this !== self::NativeVideo;
    }
}

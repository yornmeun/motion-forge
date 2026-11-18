<?php

namespace App\Enums;

enum AICapability: string
{
    case Text = 'text';
    case StructuredOutput = 'structured_output';
    case ScenePlanning = 'scene_planning';
    case AnimationCode = 'animation_code';
    case ImageGeneration = 'image_generation';
    case ImageAnalysis = 'image_analysis';
    case NativeVideo = 'native_video';
    case AudioTranscription = 'audio_transcription';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text generation',
            self::StructuredOutput => 'Structured JSON',
            self::ScenePlanning => 'Script & storyboard planning',
            self::AnimationCode => 'Animation code',
            self::ImageGeneration => 'Image generation',
            self::ImageAnalysis => 'Image analysis',
            self::NativeVideo => 'Native video generation',
            self::AudioTranscription => 'Audio transcription',
        };
    }
}

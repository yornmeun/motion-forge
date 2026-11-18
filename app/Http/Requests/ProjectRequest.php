<?php

namespace App\Http\Requests;

use App\Enums\ProductionMode;
use App\Models\AiCredential;
use App\Services\Production\PlanVocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creds = AiCredential::visibleTo($this->user())->pluck('id')->all();
        $kb = config('motionforge.uploads');

        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'genre' => ['nullable', 'string', 'max:60'],
            'production_mode' => ['required', Rule::enum(ProductionMode::class)],
            'workflow' => ['required', Rule::in(['approved_package', 'preproduction'])],
            'aspect_ratio' => ['required', Rule::in(['9:16', '16:9', '1:1'])],
            'resolution' => ['required', Rule::in(['1080x1920', '720x1280', '1920x1080', '1080x1080'])],
            'frame_rate' => ['required', Rule::in([24, 25, 30])],
            'target_duration_seconds' => ['nullable', 'integer', 'min:10', 'max:900'],
            'generation_strategy' => ['required', Rule::in(['ai', 'template'])],
            'ai_credential_id' => ['nullable', Rule::in($creds)],
            'ai_model' => ['nullable', 'string', 'max:120'],
            'video_credential_id' => ['nullable', Rule::in($creds)],
            'video_model' => ['nullable', 'string', 'max:120'],
            'fallback_enabled' => ['boolean'],
            'fallback_credential_id' => ['nullable', 'required_if:fallback_enabled,1', Rule::in($creds)],
            'fallback_model' => ['nullable', 'string', 'max:120'],
            'budget_limit_usd' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'settings.animation_style' => ['nullable', Rule::in(['cinematic', 'restrained', 'dynamic'])],
            'settings.camera' => ['nullable', Rule::in(['slow_push_in', 'handheld_drift', 'static_drift', 'mixed'])],
            'settings.atmosphere' => ['nullable', Rule::in(PlanVocabulary::LOOKS)],
            'settings.sound' => ['nullable', Rule::in(['cinematic', 'minimal', 'music_forward'])],
            'settings.subtitles.y' => ['nullable', 'integer', 'min:220', 'max:1450'],
            'settings.subtitles.style' => ['nullable', Rule::in(['serious', 'bold'])],
            'settings.thumbnail.title' => ['nullable', 'string', 'max:40'],
            'settings.story_idea' => ['nullable', 'string', 'max:4000'],
            // step 3 materials (optional at creation)
            'story' => ['nullable', 'file', 'mimes:txt,md', 'max:'.$kb['text_max_kb']],
            'narration' => ['nullable', 'file', 'mimes:txt', 'max:'.$kb['text_max_kb']],
            'srt' => ['nullable', 'file', 'max:'.$kb['text_max_kb']],
            'voiceover' => ['nullable', 'file', 'mimes:mp3,wav,m4a,aac,ogg,flac', 'max:'.$kb['audio_max_kb']],
            'characters' => ['nullable', 'array', 'max:6'],
            'characters.*' => ['image', 'mimes:png,jpg,jpeg,webp', 'max:'.$kb['image_max_kb']],
            'archive' => ['nullable', 'file', 'mimes:zip', 'max:'.$kb['archive_max_kb']],
        ];
    }

    public function after(): array
    {
        return [function ($v) {
            if ($this->file('srt') && strtolower($this->file('srt')->getClientOriginalExtension()) !== 'srt') {
                $v->errors()->add('srt', 'The subtitles must be an .srt file.');
            }
        }];
    }
}

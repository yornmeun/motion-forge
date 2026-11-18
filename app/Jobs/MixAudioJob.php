<?php

namespace App\Jobs;

use App\Jobs\Concerns\RenderPipelineStep;
use App\Models\AudioAsset;
use App\Models\Project;
use App\Models\RenderArtifact;
use App\Models\RenderJob;
use App\Services\Media\Ffmpeg;
use App\Services\Rendering\AudioMixer;
use App\Services\Rendering\KikitoonsEngine;

class MixAudioJob extends RenderPipelineStep
{
    protected function stageName(): string { return 'Sound design and audio mixing'; }

    protected function run(RenderJob $rj, Project $project): void
    {
        $engine = app(KikitoonsEngine::class);
        $work = $engine->tmp('mix');
        try {
            $scenes = $project->scenes()->with('currentVersion')->get();
            $duration = (float) $scenes->last()->end_time;
            $disk = $this->disk();
            $capsRel = $project->storageDir('renders/caps.json');
            $caps = $disk->exists($capsRel) ? json_decode($disk->get($capsRel), true) : [];
            $mixer = app(AudioMixer::class);
            $sheet = $mixer->cueSheet($scenes, $duration, $caps ?? []);
            $voice = $project->approvedPackage?->voiceover?->absolutePath();
            if (! $voice) {
                // no narration (native video without a package): mix the bed against digital silence
                $voice = $work.'/silence.wav';
                app(Ffmpeg::class)->mustRun([config('motionforge.ffmpeg'), '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=mono', '-t', (string) $duration, $voice], 120);
            }
            $rel = $project->storageDir('renders/mix.wav');
            $disk->makeDirectory(dirname($rel));
            $levels = $mixer->mix($project, $voice, $sheet, $work, $disk->path($rel));
            $sheetRel = $project->storageDir('renders/sfx_cues.json');
            $disk->put($sheetRel, json_encode($sheet, JSON_PRETTY_PRINT));
            AudioAsset::create(['project_id' => $project->id, 'type' => 'mix', 'path' => $rel, 'duration' => $duration, 'loudness_lufs' => $levels['lufs'], 'metadata' => $levels + ['cues' => count($sheet['cues'])]]);
            RenderArtifact::create(['render_job_id' => $rj->id, 'project_id' => $project->id, 'type' => 'mix', 'path' => $rel, 'size' => $disk->size($rel), 'metadata' => $levels]);
            $rj->appendLog(sprintf('Mix: %d SFX cues, %d music beds, %s LUFS, true peak %s dBFS', count($sheet['cues']), count($sheet['music']), $levels['lufs'] ?? 'n/a', $levels['true_peak'] ?? 'n/a'));
        } finally {
            $engine->cleanup($work);
        }
    }
}

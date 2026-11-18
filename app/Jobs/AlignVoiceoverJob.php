<?php

namespace App\Jobs;

use App\Jobs\Concerns\RenderPipelineStep;
use App\Models\Project;
use App\Models\RenderArtifact;
use App\Models\RenderJob;
use App\Models\SubtitleTrack;
use App\Services\Media\Ffmpeg;
use App\Services\Production\PackageService;
use App\Services\Production\SrtParser;
use App\Services\Rendering\StoryAssembler;
use RuntimeException;

/**
 * Verifies the locked package, checks the voice against the SRT (speech onsets vs cue starts),
 * confirms every scene covers its narration, and writes caps.json + the final SRT (exact approved
 * words, verified timings, title as block 1 when there is room before the first line).
 */
class AlignVoiceoverJob extends RenderPipelineStep
{
    protected function stageName(): string { return 'Aligning voice and subtitles'; }

    protected function run(RenderJob $rj, Project $project): void
    {
        if (! $project->production_mode->requiresLockedPackage() && ! $project->approvedPackage) {
            $rj->appendLog('No narration package: video will have music and sound design only.');

            return;
        }
        $package = app(PackageService::class)->assertLocked($project);
        $srt = app(SrtParser::class);
        $cues = $srt->parse($package->srt_text);
        $scenes = $project->scenes()->with('currentVersion')->get();
        foreach ($scenes->where('kind', 'story') as $s) {
            $first = $cues[$s->cue_start] ?? null; $last = $cues[$s->cue_end] ?? null;
            if (! $first || ! $last) throw new RuntimeException("Scene {$s->sequence} references cues that do not exist.");
            if ($first['start'] < $s->start_time - 0.01 || ($last['end'] > $s->end_time + 0.35 && $s->sequence !== $scenes->where('kind', 'story')->max('sequence'))) {
                throw new RuntimeException(sprintf('Scene %d (%.2f–%.2f s) does not contain its narration (%.2f–%.2f s). Adjust the timing in the editor.', $s->sequence, $s->start_time, $s->end_time, $first['start'], $last['end']));
            }
        }
        // speech onsets vs SRT starts (single-file voice: matched by pauses, can be a fraction of a second off)
        $ffmpeg = app(Ffmpeg::class);
        $silences = $ffmpeg->silences($package->voiceover->absolutePath());
        $onsets = array_column($silences, 'end');
        $offsets = [];
        foreach ($cues as $c) {
            $near = collect($onsets)->sortBy(fn ($o) => abs($o - $c['start']))->first();
            if ($near !== null && abs($near - $c['start']) < 1.0) $offsets[] = $near - $c['start'];
        }
        $mean = $offsets ? array_sum($offsets) / count($offsets) : null;
        $rj->appendLog(sprintf('Voice %.2f s · %d cues · %d speech onsets matched%s', $package->audio_duration, count($cues), count($offsets), $mean !== null ? sprintf(' · mean offset %+.2f s', $mean) : ''));

        $capsJs = app(StoryAssembler::class)->caps($cues, $scenes, 0);
        $caps = json_decode(substr($capsJs, strlen('const CAPS = '), -2), true);
        $disk = $this->disk();
        $capsRel = $project->storageDir('renders/caps.json');
        $disk->put($capsRel, json_encode($caps, JSON_UNESCAPED_UNICODE));
        $final = $cues;
        if ($cues[0]['start'] >= 0.8) {
            array_unshift($final, ['index' => -1, 'start' => 0.0, 'end' => round(min(1.5, $cues[0]['start'] - 0.1), 3), 'text' => $project->title]);
        }
        $srtText = $srt->format($final);
        $srtRel = $project->storageDir('renders/final.srt');
        $disk->put($srtRel, $srtText);
        SubtitleTrack::updateOrCreate(['project_id' => $project->id, 'type' => 'final'], ['production_package_id' => $package->id, 'cues' => $final, 'srt_text' => $srtText]);
        foreach ([['caps', $capsRel], ['srt_final', $srtRel]] as [$type, $rel]) {
            RenderArtifact::create(['render_job_id' => $rj->id, 'project_id' => $project->id, 'type' => $type, 'path' => $rel, 'size' => $disk->size($rel), 'metadata' => ['mean_onset_offset' => $mean, 'matched_onsets' => count($offsets)]]);
        }
    }
}

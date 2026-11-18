<?php

namespace App\Services\Rendering;

use App\Models\Project;
use App\Services\Media\Ffmpeg;
use App\Services\Production\PlanVocabulary;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Four-layer mix (voice, music, ambience, SFX). The bed is synthesised from the scene plan's sound
 * columns, ducked under the voice with a sidechain compressor, then two-pass loudness-normalised
 * to −14 LUFS integrated.
 */
class AudioMixer
{
    public function __construct(private Ffmpeg $ffmpeg) {}

    /** Build the cue sheet from the plan. Every SFX is placed at a scene start or a spoken keyword. */
    public function cueSheet(Collection $scenes, float $duration, array $caps = []): array
    {
        $music = []; $amb = []; $cues = []; $silences = [];
        $musicStart = 0; $curMusic = null; $ambStart = 0; $curAmb = null;
        foreach ($scenes->values() as $i => $s) {
            $p = $s->plan();
            $start = (float) $s->start_time;
            $mood = $p['music'] ?? 'dark_drone';
            if ($mood !== $curMusic) {
                if ($curMusic && $curMusic !== 'silence') $music[] = ['t0' => $musicStart, 't1' => $start + 0.8, 'mood' => $curMusic, 'gain' => -18];
                $curMusic = $mood; $musicStart = $start;
            }
            $a = $p['ambience'] ?? (PlanVocabulary::AMBIENCE[$p['background'] ?? 'hallway'] ?? 'room_tone');
            if ($a !== $curAmb) {
                if ($curAmb) $amb[] = ['t0' => $ambStart, 't1' => $start + 0.8, 'kind' => $curAmb, 'gain' => -30];
                $curAmb = $a; $ambStart = $start;
            }
            $fx = array_values($p['sound_effects'] ?? []);
            foreach ($fx as $k => $kind) {
                $at = $start + ($i === 0 && $k === 0 ? 0 : 0.05 + $k * 0.6);
                $cues[] = ['t' => round($at, 3), 'kind' => $kind, 'gain' => in_array($kind, ['boom', 'braam', 'subdrop'], true) ? -4 : -9, 'dur' => $kind === 'footsteps' ? min(3, $s->duration()) : null];
            }
            if (($p['transition'] ?? 'none') === 'whip' && $i > 0) $cues[] = ['t' => $start - 0.2, 'kind' => 'whoosh', 'gain' => -10];
            if (($p['transition'] ?? 'none') === 'smash' && $i > 0) $cues[] = ['t' => $start, 'kind' => 'hit_metal', 'gain' => -8];
            if (! empty($p['slam_word'])) {
                $t = $this->wordTime($caps, $p['slam_word']) ?? $start;
                $silences[] = [max(0, $t - 0.9), $t - 0.05];
                $cues[] = ['t' => $t - 2, 'kind' => 'reverse_swell', 'gain' => -10, 'dur' => 2];
                $cues[] = ['t' => $t, 'kind' => 'braam', 'gain' => -3];
            }
            if (($p['kind'] ?? '') === 'end_card') {
                $cues[] = ['t' => $start + 2.2, 'kind' => 'low_hit', 'gain' => -8];
            }
        }
        if ($curMusic && $curMusic !== 'silence') $music[] = ['t0' => $musicStart, 't1' => $duration, 'mood' => $curMusic, 'gain' => -18];
        if ($curAmb) $amb[] = ['t0' => $ambStart, 't1' => $duration, 'kind' => $curAmb, 'gain' => -30];
        $last = $scenes->last();
        if ($last) $cues[] = ['t' => max(0, $duration - 1.4), 'kind' => 'button', 'gain' => -10];

        return ['duration' => $duration, 'sampleRate' => 48000, 'music' => $music, 'ambience' => $amb, 'cues' => $cues, 'silences' => $silences];
    }

    public function wordTime(array $caps, string $word): ?float
    {
        foreach ($caps as $cue) foreach ($cue['words'] ?? [] as $w) {
            if (mb_strtolower(trim($w['w'], " .,!?;:\"'…")) === mb_strtolower($word)) return (float) $w['start'];
        }

        return null;
    }

    /** @return array{lufs: ?float, true_peak: ?float} */
    public function mix(Project $project, string $voiceAbs, array $sheet, string $work, string $outAbs): array
    {
        file_put_contents($work.'/cues.json', json_encode($sheet));
        $p = SandboxProcess::make([config('motionforge.node'), config('motionforge.sfx'), $work.'/cues.json', $work.'/bed.wav'], $work, 300);
        $p->run();
        if (! $p->isSuccessful()) {
            throw new RuntimeException('Sound bed synthesis failed: '.mb_substr($p->getErrorOutput(), 0, 400));
        }
        $dur = number_format($sheet['duration'], 3, '.', '');
        // voice + bed (bed ducked by a sidechain keyed on the voice), padded/trimmed to the video length
        $graph = '[0:a]aformat=sample_rates=48000:channel_layouts=mono,apad,atrim=0:'.$dur.',asplit=2[v][key];'
            .'[1:a]aformat=sample_rates=48000:channel_layouts=mono,apad,atrim=0:'.$dur.'[bed];'
            .'[bed][key]sidechaincompress=threshold=0.02:ratio=10:attack=15:release=450:makeup=1[duck];'
            .'[v][duck]amix=inputs=2:normalize=0:duration=first,alimiter=limit=0.95[out]';
        $this->ffmpeg->mustRun([$this->ffmpeg->bin(), '-y', '-hide_banner', '-loglevel', 'error', '-i', $voiceAbs, '-i', $work.'/bed.wav', '-filter_complex', $graph, '-map', '[out]', '-ar', '48000', '-ac', '2', $work.'/premix.wav'], 900);
        // two-pass loudnorm to -14 LUFS
        $target = (float) config('motionforge.defaults.loudness_lufs', -14);
        $measure = $this->ffmpeg->run([$this->ffmpeg->bin(), '-hide_banner', '-nostats', '-i', $work.'/premix.wav', '-af', "loudnorm=I={$target}:TP=-1.5:LRA=11:print_format=json", '-f', 'null', '-'], 600)->getErrorOutput();
        preg_match('/\{[^{}]*"input_i"[^{}]*\}/s', $measure, $m);
        $j = json_decode($m[0] ?? '{}', true) ?: [];
        $ln = "loudnorm=I={$target}:TP=-1.5:LRA=11";
        if (isset($j['input_i'], $j['input_tp'], $j['input_lra'], $j['input_thresh'], $j['target_offset'])) {
            $ln .= ":measured_I={$j['input_i']}:measured_TP={$j['input_tp']}:measured_LRA={$j['input_lra']}:measured_thresh={$j['input_thresh']}:offset={$j['target_offset']}:linear=true";
        }
        $this->ffmpeg->mustRun([$this->ffmpeg->bin(), '-y', '-hide_banner', '-loglevel', 'error', '-i', $work.'/premix.wav', '-af', $ln, '-ar', '48000', '-ac', '2', $outAbs], 900);
        $l = $this->ffmpeg->loudness($outAbs);

        return ['lufs' => $l['integrated'], 'true_peak' => $l['true_peak']];
    }
}

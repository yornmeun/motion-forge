<?php

namespace App\Services\Rendering;

use App\Models\Project;
use App\Models\QualityCheck;
use App\Models\RenderArtifact;
use App\Services\Media\Ffmpeg;
use Throwable;

/**
 * Technical QC on the rendered file. Creative review is a separate, human checklist and is never
 * auto-passed.
 */
class QualityControl
{
    public const CREATIVE = [
        'character_consistency' => 'Kiki is on-model in every scene (backwards red cap, brim left, freckles, yellow tee)',
        'cinematic_tone' => 'Serious, cinematic tone: muted grade, no comedy effects or sounds',
        'scene_progression' => 'Every plot turn from the locked story is on screen, in order',
        'camera_movement' => 'Every shot moves with intent; no identical angle twice in a row',
        'emotional_acting' => 'Expressions and body language match each line',
        'subtitle_readability' => 'Subtitles readable, in the safe zone, never over a face',
        'meaningful_action' => 'Each scene shows the narrated action, not a static illustration',
        'memorable_ending' => 'Strong final image, then the end card with true advice and FOLLOW FOR MORE VIDEOS',
    ];

    public function __construct(private Ffmpeg $ffmpeg) {}

    /** @return list<array> results */
    public function run(Project $project, RenderArtifact $final, float $expectedDuration, array $caps = []): array
    {
        $project->qualityChecks()->where('kind', 'technical')->delete();
        $abs = \Storage::disk(config('motionforge.disk'))->path($final->path);
        $results = [];
        $add = function (string $key, string $status, ?string $value, ?string $expected, string $message) use (&$results, $project, $final) {
            $results[] = QualityCheck::create(['project_id' => $project->id, 'render_artifact_id' => $final->id, 'kind' => 'technical', 'check_key' => $key, 'status' => $status, 'value' => $value, 'expected' => $expected, 'message' => $message])->toArray();
        };

        try {
            $probe = $this->ffmpeg->probe($abs);
        } catch (Throwable $e) {
            $add('readable', 'fail', null, 'readable MP4', 'The output cannot be read: '.$e->getMessage());

            return $results;
        }
        $add('readable', 'pass', 'ok', 'readable MP4', 'FFprobe reads the file.');
        $v = collect($probe['streams'])->firstWhere('codec_type', 'video');
        $a = collect($probe['streams'])->firstWhere('codec_type', 'audio');
        $res = $v ? "{$v['width']}x{$v['height']}" : 'none';
        $want = "{$project->width}x{$project->height}";
        $add('resolution', $res === $want ? 'pass' : 'fail', $res, $want, $res === $want ? 'Resolution matches.' : 'Wrong resolution.');
        $ratio = $v ? round($v['width'] / max(1, $v['height']), 4) : 0;
        [$aw, $ah] = array_map('intval', explode(':', $project->aspect_ratio));
        $add('aspect_ratio', abs($ratio - $aw / max(1, $ah)) < 0.01 ? 'pass' : 'fail', (string) $ratio, $project->aspect_ratio, 'Display aspect ratio check.');
        $fps = $v ? $this->rate($v['avg_frame_rate'] ?? $v['r_frame_rate'] ?? '0/1') : 0;
        $add('frame_rate', abs($fps - $project->frame_rate) < 0.05 ? 'pass' : 'fail', (string) round($fps, 3), (string) $project->frame_rate, 'Frame rate check.');
        $codecs = ($v['codec_name'] ?? '-').' + '.($a['codec_name'] ?? '-');
        $add('codecs', ($v['codec_name'] ?? '') === 'h264' && ($a['codec_name'] ?? '') === 'aac' ? 'pass' : 'fail', $codecs, 'h264 + aac', 'Delivery codecs.');
        $dur = (float) $probe['duration'];
        $delta = abs($dur - $expectedDuration);
        $add('duration', $delta <= 0.5 ? 'pass' : 'fail', number_format($dur, 2).' s', number_format($expectedDuration, 2).' s', $delta <= 0.5 ? 'Duration matches the timeline.' : 'Unexpected duration: scenes may be missing or truncated.');
        if ($project->production_mode->value === 'kikitoons') {
            $add('length_range', $dur >= 120 && $dur <= 180 ? 'pass' : 'warn', gmdate('i:s', (int) $dur), '2:00–3:00', 'Kikitoons target length (the approved voice sets the actual length).');
        }
        if (! $a) {
            $add('audio_present', 'fail', 'none', 'audio stream', 'The video has no audio.');
        } else {
            $add('audio_present', 'pass', $a['codec_name'], 'audio stream', 'Audio present.');
            $l = $this->ffmpeg->loudness($abs);
            $lufs = $l['integrated'];
            $target = (float) config('motionforge.defaults.loudness_lufs', -14);
            $add('loudness', $lufs !== null && abs($lufs - $target) <= 1.0 ? 'pass' : 'warn', $lufs !== null ? $lufs.' LUFS' : 'n/a', $target.' LUFS', 'Integrated loudness.');
            $tp = $l['true_peak'];
            $add('clipping', $tp === null || $tp <= -0.5 ? 'pass' : ($tp < 0.5 ? 'warn' : 'fail'), $tp !== null ? $tp.' dBFS' : 'n/a', '≤ −0.5 dBFS', 'True-peak / clipping check.');
        }
        $black = $this->ffmpeg->run([$this->ffmpeg->bin(), '-hide_banner', '-nostats', '-i', $abs, '-vf', 'blackdetect=d=0.6:pic_th=0.97', '-an', '-f', 'null', '-'], 1800)->getErrorOutput();
        preg_match_all('/black_start:([\d.]+) black_end:([\d.]+)/', $black, $bm, PREG_SET_ORDER);
        $add('black_frames', $bm ? 'fail' : 'pass', $bm ? count($bm).' span(s)' : 'none', 'no black > 0.6 s', $bm ? 'Black frames at '.collect($bm)->map(fn ($m) => round((float) $m[1], 1).'–'.round((float) $m[2], 1).' s')->implode(', ') : 'No black stretches.');
        $freeze = $this->ffmpeg->run([$this->ffmpeg->bin(), '-hide_banner', '-nostats', '-i', $abs, '-vf', 'freezedetect=n=0.0008:d=1.5', '-an', '-f', 'null', '-'], 1800)->getErrorOutput();
        preg_match_all('/freeze_start: ([\d.]+)/', $freeze, $fm);
        preg_match_all('/freeze_end: ([\d.]+)/', $freeze, $fe);
        $spans = collect($fm[1])->map(fn ($s, $i) => [(float) $s, (float) ($fe[1][$i] ?? $dur)])->filter(fn ($s) => $s[0] < $dur - 1.6); // the final 1 s hold is intentional
        $add('frozen_frames', $spans->isEmpty() ? 'pass' : 'warn', $spans->count().' span(s)', 'no static stretch > 1.5 s', $spans->isEmpty() ? 'Motion throughout.' : 'Static stretches at '.$spans->map(fn ($s) => round($s[0], 1).'–'.round($s[1], 1).' s')->implode(', '));
        if ($caps) {
            $bad = 0; $prev = -1;
            foreach ($caps as $c) {
                if ($c['start'] < $prev - 0.05 || $c['end'] > $dur + 0.05 || $c['end'] <= $c['start']) $bad++;
                $prev = $c['start'];
            }
            $add('subtitle_timing', $bad ? 'fail' : 'pass', $bad.' issue(s)', 'ordered, inside the video', $bad ? 'Some subtitle cues are out of order or outside the video.' : count($caps).' cues ordered and within the video.');
        }

        return $results;
    }

    public function checkThumbnail(Project $project, ?string $abs): void
    {
        $project->qualityChecks()->where('check_key', 'thumbnail')->delete();
        $ok = $abs && is_file($abs) && ($size = @getimagesize($abs)) && $size[0] === 1080 && $size[1] === 1920;
        QualityCheck::create(['project_id' => $project->id, 'kind' => 'technical', 'check_key' => 'thumbnail', 'status' => $ok ? 'pass' : 'fail', 'value' => $abs && is_file($abs) ? ($size[0] ?? '?').'x'.($size[1] ?? '?') : 'missing', 'expected' => '1080x1920 PNG', 'message' => $ok ? 'Thumbnail present.' : 'Thumbnail missing or wrong size.']);
    }

    public function seedCreativeChecklist(Project $project): void
    {
        foreach (self::CREATIVE as $key => $label) {
            QualityCheck::firstOrCreate(['project_id' => $project->id, 'kind' => 'creative', 'check_key' => $key], ['status' => 'pending', 'message' => $label]);
        }
    }

    private function rate(string $r): float
    {
        [$a, $b] = array_pad(explode('/', $r), 2, 1);

        return (float) $b ? (float) $a / (float) $b : 0;
    }
}

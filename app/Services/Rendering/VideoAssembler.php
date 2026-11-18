<?php

namespace App\Services\Rendering;

use App\Models\Project;
use App\Services\Media\Ffmpeg;

/** FFmpeg assembly: concat scene segments, normalise AI clips, mux the mix, optional burned subtitles. */
class VideoAssembler
{
    public function __construct(private Ffmpeg $ffmpeg) {}

    /** Concatenate same-encoded segments without re-encoding. */
    public function concat(array $segmentsAbs, string $work, string $outAbs): void
    {
        $list = $work.'/concat.txt';
        file_put_contents($list, implode("\n", array_map(fn ($p) => "file '".str_replace("'", "'\\''", $p)."'", $segmentsAbs))."\n");
        $this->ffmpeg->mustRun([$this->ffmpeg->bin(), '-y', '-hide_banner', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', $list, '-c', 'copy', '-movflags', '+faststart', $outAbs], 1800);
    }

    /** Scale/crop an AI clip to the project frame, set fps, and trim/pad to an exact duration. */
    public function normaliseClip(Project $project, string $inAbs, float $duration, string $outAbs): void
    {
        $w = $project->width; $h = $project->height; $fps = $project->frame_rate;
        $d = number_format($duration, 3, '.', '');
        $vf = "scale={$w}:{$h}:force_original_aspect_ratio=increase,crop={$w}:{$h},fps={$fps},tpad=stop_mode=clone:stop_duration={$d},trim=duration={$d},setpts=PTS-STARTPTS,format=yuv420p";
        $this->ffmpeg->mustRun([$this->ffmpeg->bin(), '-y', '-hide_banner', '-loglevel', 'error', '-i', $inAbs, '-an', '-vf', $vf, '-c:v', 'libx264', '-preset', 'medium', '-crf', '19', '-r', (string) $fps, $outAbs], 900);
    }

    /** Final mux: H.264 video (copied, or re-encoded with burned ASS subtitles) + AAC audio. */
    public function mux(string $videoAbs, string $audioAbs, string $outAbs, ?string $assAbs = null, int $fps = 30): void
    {
        $args = [$this->ffmpeg->bin(), '-y', '-hide_banner', '-loglevel', 'error', '-i', $videoAbs, '-i', $audioAbs, '-map', '0:v:0', '-map', '1:a:0'];
        if ($assAbs) {
            $esc = fn (string $p) => str_replace([':', "'", ','], ['\\:', "\\'", '\\,'], $p);
            $args = [...$args, '-vf', 'ass=filename='.$esc($assAbs).':fontsdir='.$esc(dirname(config('motionforge.font'))), '-c:v', 'libx264', '-preset', 'medium', '-crf', '19', '-pix_fmt', 'yuv420p', '-r', (string) $fps];
        } else {
            $args = [...$args, '-c:v', 'copy'];
        }
        $this->ffmpeg->mustRun([...$args, '-c:a', 'aac', '-b:a', '192k', '-ar', '48000', '-shortest', '-movflags', '+faststart', $outAbs], 3600);
    }

    /** ASS subtitles (used where captions are not drawn by the Canvas engine: native/hybrid video). */
    public function ass(array $caps, int $w, int $h, int $y = 1344): string
    {
        $fmt = fn (float $s) => sprintf('%d:%02d:%02d.%02d', intdiv((int) $s, 3600), intdiv((int) $s % 3600, 60), (int) $s % 60, (int) round(($s - floor($s)) * 100) % 100);
        $out = "[Script Info]\nScriptType: v4.00+\nPlayResX: {$w}\nPlayResY: {$h}\nWrapStyle: 0\n\n[V4+ Styles]\n"
            ."Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n"
            ."Style: Default,Poppins,70,&H00E6EFF3,&H00E6EFF3,&H000C080A,&H64000000,-1,0,0,0,100,100,0,0,1,7,0,2,90,90,".($h - $y - 40).",1\n\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
        foreach ($caps as $cue) {
            $text = collect($cue['words'] ?? [])->map(function ($w) {
                $t = mb_strtoupper(str_replace(['{', '}', '\\'], '', $w['w']));

                return ($w['kind'] ?? '') !== 'plain' ? '{\\c&H3AA6E0&}'.$t.'{\\c&HE6EFF3&}' : $t;
            })->implode(' ');
            $out .= 'Dialogue: 0,'.$fmt($cue['start']).','.$fmt($cue['end']).',Default,,0,0,0,,{\\fad(120,80)}'.$text."\n";
        }

        return $out;
    }
}

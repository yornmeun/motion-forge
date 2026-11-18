<?php

namespace App\Services\Media;

use RuntimeException;
use Symfony\Component\Process\Process;

/** FFmpeg / FFprobe runner. Arguments are always passed as arrays (no shell). */
class Ffmpeg
{
    public function bin(): string { return config('motionforge.ffmpeg'); }
    public function probeBin(): string { return config('motionforge.ffprobe'); }

    public function available(): bool
    {
        try {
            return $this->run([$this->bin(), '-version'], 10)->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function run(array $args, int $timeout = 600, ?string $cwd = null): Process
    {
        $p = new Process($args, $cwd, \App\Services\Rendering\SandboxProcess::env(sys_get_temp_dir()));
        $p->setTimeout($timeout);
        $p->run();

        return $p;
    }

    public function mustRun(array $args, int $timeout = 600): string
    {
        $p = $this->run($args, $timeout);
        if (! $p->isSuccessful()) {
            throw new RuntimeException('FFmpeg failed: '.mb_substr(trim($p->getErrorOutput()), -800));
        }

        return $p->getOutput().$p->getErrorOutput();
    }

    /** @return array{duration: float, streams: array, format: array} */
    public function probe(string $path): array
    {
        $p = $this->run([$this->probeBin(), '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path], 60);
        if (! $p->isSuccessful()) {
            throw new RuntimeException('Unreadable media file: '.mb_substr(trim($p->getErrorOutput()), 0, 300));
        }
        $j = json_decode($p->getOutput(), true) ?: [];

        return ['duration' => (float) ($j['format']['duration'] ?? 0), 'streams' => $j['streams'] ?? [], 'format' => $j['format'] ?? []];
    }

    public function audioDuration(string $path): float
    {
        $probe = $this->probe($path);
        $audio = collect($probe['streams'])->firstWhere('codec_type', 'audio');
        if (! $audio) {
            throw new RuntimeException('The file has no audio stream.');
        }

        return (float) ($audio['duration'] ?? $probe['duration']);
    }

    /** @return array<int, array{start: float, end: float}> silent spans */
    public function silences(string $path, float $noiseDb = -35, float $minSec = 0.35): array
    {
        $out = $this->run([$this->bin(), '-hide_banner', '-nostats', '-i', $path, '-af', "silencedetect=noise={$noiseDb}dB:d={$minSec}", '-f', 'null', '-'], 300)->getErrorOutput();
        preg_match_all('/silence_start: ([\d.]+)/', $out, $s);
        preg_match_all('/silence_end: ([\d.]+)/', $out, $e);
        $spans = [];
        foreach ($s[1] as $i => $start) {
            $spans[] = ['start' => (float) $start, 'end' => (float) ($e[1][$i] ?? $start)];
        }

        return $spans;
    }

    /** Integrated loudness / true peak via ebur128. */
    public function loudness(string $path): array
    {
        $out = $this->run([$this->bin(), '-hide_banner', '-nostats', '-i', $path, '-af', 'ebur128=peak=true', '-f', 'null', '-'], 600)->getErrorOutput();
        $summary = substr($out, (int) strrpos($out, 'Summary:'));
        preg_match('/I:\s+(-?[\d.]+) LUFS/', $summary, $i);
        preg_match('/Peak:\s+(-?[\d.]+|-inf) dBFS/', $summary, $pk);

        return ['integrated' => isset($i[1]) ? (float) $i[1] : null, 'true_peak' => isset($pk[1]) && $pk[1] !== '-inf' ? (float) $pk[1] : null];
    }
}

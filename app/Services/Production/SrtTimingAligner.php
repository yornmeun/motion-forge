<?php

namespace App\Services\Production;

use App\Services\Media\Ffmpeg;
use InvalidArgumentException;

/** Retimes subtitle cues to the available speech spans without changing their text. */
class SrtTimingAligner
{
    public function __construct(private SrtParser $srt, private Ffmpeg $ffmpeg) {}

    /** @return array{srt: string, cues: int, duration: float, method: string}|null */
    public function alignIfNeeded(string $srt, string $audioPath): ?array
    {
        try {
            $cues = $this->srt->parseForAlignment($srt);
            $duration = $this->ffmpeg->audioDuration($audioPath);
        } catch (\Throwable) {
            return null;
        }
        if (! $this->requiresAlignment($cues, $duration)) {
            return null;
        }

        try {
            $silences = $this->ffmpeg->silences($audioPath);
        } catch (\Throwable) {
            $silences = [];
        }

        return [
            'srt' => $this->srt->format($this->align($cues, $duration, $silences)),
            'cues' => count($cues),
            'duration' => round($duration, 3),
            'method' => $silences ? 'speech-pauses' : 'voice-duration',
        ];
    }

    /** @param list<array{index: int, start: float, end: float, text: string}> $cues */
    public function requiresAlignment(array $cues, float $audioDuration): bool
    {
        if ($audioDuration <= 0 || ! $cues) {
            return false;
        }

        $previousStart = -1.0;
        foreach ($cues as $cue) {
            $textLength = mb_strlen(trim($cue['text']));
            $minimumDisplay = max(0.2, $textLength / 30);
            if (
                $cue['end'] <= $cue['start']
                || $cue['start'] < $previousStart
                || $cue['start'] >= $audioDuration
                || $cue['end'] > $audioDuration + 0.05
                || ($textLength > 0 && $minimumDisplay > $cue['end'] - $cue['start'])
            ) {
                return true;
            }
            $previousStart = $cue['start'];
        }

        return $audioDuration - $cues[array_key_last($cues)]['end'] > 3;
    }

    /** @param list<array{index: int, start: float, end: float, text: string}> $cues
     * @param  list<array{start: float, end: float}>  $silences
     * @return list<array{index: int, start: float, end: float, text: string}>
     */
    public function align(array $cues, float $audioDuration, array $silences): array
    {
        if ($audioDuration <= 0 || ! $cues) {
            throw new InvalidArgumentException('Subtitle alignment requires cues and a readable voiceover.');
        }

        $spans = $this->speechSpans($audioDuration, $silences);
        $totalSpeech = array_sum(array_map(fn (array $span) => $span[1] - $span[0], $spans));
        $weights = array_map(fn (array $cue) => max(1, mb_strlen(trim($cue['text']))), $cues);
        $totalWeight = array_sum($weights);
        $aligned = [];
        $offset = 0;

        foreach ($cues as $index => $cue) {
            $startSpeech = $offset / $totalWeight * $totalSpeech;
            $offset += $weights[$index];
            $endSpeech = $offset / $totalWeight * $totalSpeech;
            $start = $this->speechTime($startSpeech, $spans, true);
            $end = $this->speechTime($endSpeech, $spans, false);
            $start = min(max(0, $start), max(0, $audioDuration - 0.15));
            $end = min($audioDuration, max($start + 0.15, $end));

            $aligned[] = [
                'index' => $index,
                'start' => round($start, 3),
                'end' => round($end, 3),
                'text' => $cue['text'],
            ];
        }

        return $aligned;
    }

    /** @param list<array{start: float, end: float}> $silences
     * @return list<array{0: float, 1: float}>
     */
    private function speechSpans(float $duration, array $silences): array
    {
        $spans = [];
        $cursor = 0.0;
        foreach ($silences as $silence) {
            $start = min($duration, max($cursor, (float) $silence['start']));
            $end = min($duration, max($start, (float) $silence['end']));
            if ($start - $cursor > 0.15) {
                $spans[] = [$cursor, $start];
            }
            $cursor = max($cursor, $end);
        }
        if ($duration - $cursor > 0.15) {
            $spans[] = [$cursor, $duration];
        }

        return $spans ?: [[0.0, $duration]];
    }

    /** @param list<array{0: float, 1: float}> $spans */
    private function speechTime(float $position, array $spans, bool $preferNextSpan): float
    {
        $elapsed = 0.0;
        foreach ($spans as $index => [$start, $end]) {
            $length = $end - $start;
            if ($position < $elapsed + $length || (! $preferNextSpan && $position <= $elapsed + $length) || $index === array_key_last($spans)) {
                return min($end, $start + max(0, $position - $elapsed));
            }
            $elapsed += $length;
        }

        return $spans[array_key_last($spans)][1];
    }
}

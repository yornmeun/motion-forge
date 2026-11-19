<?php

namespace App\Services\Production;

use InvalidArgumentException;

class SrtParser
{
    /** @return list<array{index: int, start: float, end: float, text: string}> */
    public function parse(string $srt): array
    {
        return $this->parseSrt($srt, false);
    }

    /** @return list<array{index: int, start: float, end: float, text: string}> */
    public function parseForAlignment(string $srt): array
    {
        return $this->parseSrt($srt, true);
    }

    /**
     * Line-based SRT reader. A cue starts at each "-->" timing line; a bare number right before it is the
     * cue number. This also reads files without blank lines between cues, and ignores notes before the
     * first cue (e.g. "PROVISIONAL — RETIME TO AUDIO"), so they never count as spoken words.
     *
     * @return list<array{index: int, start: float, end: float, text: string}>
     */
    private function parseSrt(string $srt, bool $allowInvalidTiming): array
    {
        $srt = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', $srt));
        $lines = array_map('trim', explode("\n", $srt));
        $timing = '/^(\d{1,2}):(\d{2}):(\d{2})[,.](\d{1,3})\s*-->\s*(\d{1,2}):(\d{2}):(\d{2})[,.](\d{1,3})/';
        $cues = [];
        $current = null;
        foreach ($lines as $i => $line) {
            if (preg_match($timing, $line, $m)) {
                if ($current) {
                    $cues[] = $this->cue($current, count($cues), $allowInvalidTiming);
                }
                $current = ['m' => $m, 'text' => []];

                continue;
            }
            if ($current === null || $line === '') {
                continue;
            }
            $isCueNumber = ctype_digit($line) && preg_match($timing, $this->nextNonEmpty($lines, $i));
            if (! $isCueNumber) {
                $current['text'][] = $line;
            }
        }
        if ($current) {
            $cues[] = $this->cue($current, count($cues), $allowInvalidTiming);
        }
        if (! $cues) {
            throw new InvalidArgumentException('The SRT file has no cues (no "00:00:00,000 --> 00:00:02,000" timing lines were found).');
        }

        return $cues;
    }

    /** @param array{m: array<int, string>, text: list<string>} $raw */
    private function cue(array $raw, int $index, bool $allowInvalidTiming): array
    {
        $m = $raw['m'];
        $start = $m[1] * 3600 + $m[2] * 60 + $m[3] + ((int) str_pad($m[4], 3, '0')) / 1000;
        $end = $m[5] * 3600 + $m[6] * 60 + $m[7] + ((int) str_pad($m[8], 3, '0')) / 1000;
        if (! $allowInvalidTiming && $end <= $start) {
            throw new InvalidArgumentException(sprintf(
                'SRT cue %d has invalid timing: %s --> %s (the end must be after the start).',
                $index + 1,
                $this->ts($start),
                $this->ts($end),
            ));
        }

        return ['index' => $index, 'start' => round($start, 3), 'end' => round($end, 3), 'text' => trim(strip_tags(implode(' ', $raw['text'])))];
    }

    private function nextNonEmpty(array $lines, int $from): string
    {
        for ($i = $from + 1; $i < count($lines); $i++) {
            if ($lines[$i] !== '') {
                return $lines[$i];
            }
        }

        return '';
    }

    public function format(array $cues): string
    {
        $out = [];
        foreach (array_values($cues) as $i => $c) {
            $out[] = ($i + 1)."\n".$this->ts($c['start']).' --> '.$this->ts($c['end'])."\n".$c['text'];
        }

        return implode("\n\n", $out)."\n";
    }

    public function ts(float $s): string
    {
        $ms = (int) round($s * 1000);

        return sprintf('%02d:%02d:%02d,%03d', intdiv($ms, 3600000), intdiv($ms % 3600000, 60000), intdiv($ms % 60000, 1000), $ms % 1000);
    }
}

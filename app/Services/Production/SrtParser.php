<?php

namespace App\Services\Production;

use InvalidArgumentException;

class SrtParser
{
    /** @return list<array{index: int, start: float, end: float, text: string}> */
    public function parse(string $srt): array
    {
        $srt = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', $srt));
        $blocks = preg_split("/\n\s*\n/", trim($srt));
        $cues = [];
        foreach ($blocks as $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", trim($block))), fn ($l) => $l !== ''));
            if (! $lines) continue;
            $ti = preg_match('/-->/', $lines[0]) ? 0 : 1;
            if (! isset($lines[$ti]) || ! preg_match('/(\d{1,2}):(\d{2}):(\d{2})[,.](\d{1,3})\s*-->\s*(\d{1,2}):(\d{2}):(\d{2})[,.](\d{1,3})/', $lines[$ti], $m)) {
                throw new InvalidArgumentException('Malformed SRT block: '.mb_substr($block, 0, 80));
            }
            $start = $m[1] * 3600 + $m[2] * 60 + $m[3] + ((int) str_pad($m[4], 3, '0')) / 1000;
            $end = $m[5] * 3600 + $m[6] * 60 + $m[7] + ((int) str_pad($m[8], 3, '0')) / 1000;
            $text = trim(strip_tags(implode(' ', array_slice($lines, $ti + 1))));
            if ($end <= $start) {
                throw new InvalidArgumentException(sprintf('SRT cue %d ends before it starts.', count($cues) + 1));
            }
            $cues[] = ['index' => count($cues), 'start' => round($start, 3), 'end' => round($end, 3), 'text' => $text];
        }
        if (! $cues) {
            throw new InvalidArgumentException('The SRT file has no cues.');
        }

        return $cues;
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

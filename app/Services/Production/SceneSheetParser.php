<?php

namespace App\Services\Production;

/**
 * Reads the ChatGPT V2 package documents: SCENE_SHEET.md ("### SC_001 — Title" blocks with
 * "- **Field:** value" bullets) and the character / background registries ("## CHAR_01 — Name"
 * blocks). Field names are matched loosely because they vary a little between packages.
 */
class SceneSheetParser
{
    /**
     * @return list<array{id: string, title: string, cue_start: ?int, cue_end: ?int, start: ?float, end: ?float, characters: list<array{id: string, conditional: bool}>, kiki: bool, background: ?string, fields: array<string, string>}>
     */
    public function scenes(string $markdown): array
    {
        $scenes = [];
        foreach ($this->blocks($markdown, '###?', '(SC[_-]?\d+[A-Z]?)') as $block) {
            $fields = $block['fields'];
            $track = $this->field($fields, ['track', 'narration', 'srt']);
            $cueStart = $cueEnd = null;
            if (preg_match('/cues?\s*#?\s*(\d+)\s*(?:[–—-]|to)\s*(\d+)/iu', $track.' '.$this->field($fields, ['exact narration', 'subtitle']), $m)) {
                [$cueStart, $cueEnd] = [(int) $m[1], (int) $m[2]];
            }
            $start = $end = null;
            if (preg_match('/(\d{1,2}:\d{2}:\d{2}[,.]\d{1,3}|\d{1,2}:\d{2}(?::\d{2})?)\s*[–—-]\s*(\d{1,2}:\d{2}:\d{2}[,.]\d{1,3}|\d{1,2}:\d{2}(?::\d{2})?)/u', $this->field($fields, ['time']), $m)) {
                [$start, $end] = [$this->seconds($m[1]), $this->seconds($m[2])];
            }
            $charactersText = $this->field($fields, ['characters', 'cast']);
            $scenes[] = [
                'id' => $block['id'],
                'title' => $block['title'],
                'cue_start' => $cueStart,
                'cue_end' => $cueEnd,
                'start' => $start,
                'end' => $end,
                'characters' => $this->characterIds($charactersText),
                'kiki' => $this->kikiRequested($charactersText),
                'background' => preg_match('/\b(BG[_-]?\w+)\b/u', $this->field($fields, ['location', 'background']), $m) ? $this->normaliseId($m[1]) : null,
                'fields' => $fields,
            ];
        }

        return $scenes;
    }

    /** @return array<string, array{id: string, name: string, text: string}> keyed by ID (CHAR_01, BG_01, ...) */
    public function registry(string $markdown, string $prefix): array
    {
        $entries = [];
        foreach ($this->blocks($markdown, '##', '('.$prefix.'[_-]?\w+)') as $block) {
            $name = trim((string) preg_replace('/\s*\(.*$/u', '', $block['title']));
            $entries[$block['id']] = ['id' => $block['id'], 'name' => $name !== '' ? $name : $block['id'], 'text' => $block['title'].' '.implode(' ', $block['fields']).' '.$block['body']];
        }

        return $entries;
    }

    /**
     * Value of the first field whose label contains one of the given words.
     *
     * @param  array<string, string>  $fields
     * @param  list<string>  $labels
     */
    public function field(array $fields, array $labels): string
    {
        foreach ($labels as $label) {
            foreach ($fields as $key => $value) {
                if (str_contains($key, $label)) {
                    return $value;
                }
            }
        }

        return '';
    }

    /** @return list<array{id: string, title: string, fields: array<string, string>, body: string}> */
    private function blocks(string $markdown, string $hashes, string $idPattern): array
    {
        $parts = preg_split('/^(?='.$hashes.'\s+'.$idPattern.')/mu', str_replace(["\r\n", "\r"], "\n", $markdown)) ?: [];
        $blocks = [];
        foreach ($parts as $part) {
            if (! preg_match('/^'.$hashes.'\s+'.$idPattern.'\s*(?:[—–:-]+\s*)?(.*)$/mu', $part, $head)) {
                continue;
            }
            $fields = [];
            $body = [];
            foreach (array_slice(explode("\n", $part), 1) as $line) {
                if (preg_match('/^\s*[-*]\s*\*\*(.+?)\*\*\s*:?\s*(.*)$/u', $line, $m)) {
                    $fields[mb_strtolower(rtrim(trim($m[1]), ':'))] = trim(str_replace('`', '', $m[2]));
                } elseif (trim($line) !== '') {
                    $body[] = trim($line);
                }
            }
            $blocks[] = ['id' => $this->normaliseId($head[1]), 'title' => trim($head[2]), 'fields' => $fields, 'body' => implode(' ', $body)];
        }

        return $blocks;
    }

    /** @return list<array{id: string, conditional: bool}> */
    private function characterIds(string $text): array
    {
        $out = [];
        foreach (preg_split('/[;,]/u', $text) ?: [] as $segment) {
            if (! preg_match_all('/\b(CHAR[_-]?\w+)\b/u', $segment, $m)) {
                continue;
            }
            foreach ($m[1] as $id) {
                $id = $this->normaliseId($id);
                if ($id === 'CHAR_KIKI' || preg_match('/:\s*no\b/iu', $segment)) {
                    continue;
                }
                $out[$id] ??= ['id' => $id, 'conditional' => (bool) preg_match('/\b(only|where|if|when)\b/iu', $segment)];
            }
        }

        return array_values($out);
    }

    private function kikiRequested(string $text): bool
    {
        if (preg_match('/\bkiki\b\s*:\s*(yes|no)\b/iu', $text, $m)) {
            return strtolower($m[1]) === 'yes';
        }

        return (bool) preg_match('/\bCHAR[_-]?KIKI\b/iu', $text);
    }

    private function normaliseId(string $id): string
    {
        return strtoupper((string) preg_replace('/^(SC|CHAR|BG)[_-]?/iu', '$1_', $id));
    }

    private function seconds(string $ts): float
    {
        $parts = array_map('floatval', explode(':', str_replace(',', '.', $ts)));

        return array_reduce($parts, fn (float $carry, float $part) => $carry * 60 + $part, 0.0);
    }
}

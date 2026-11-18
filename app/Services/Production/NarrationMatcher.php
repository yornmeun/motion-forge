<?php

namespace App\Services\Production;

/**
 * Word-level comparison between the approved narration and another text (SRT, scene references).
 * Used to prove locked narration is unchanged; it never edits either text.
 */
class NarrationMatcher
{
    /** @return list<string> */
    public function words(string $text): array
    {
        $t = mb_strtolower(str_replace(['’', '‘', '“', '”', '—', '–', '…'], ["'", "'", '"', '"', ' ', ' ', ' '], $text));
        $t = preg_replace("/[^\p{L}\p{N}'\s]+/u", ' ', $t);
        $t = preg_replace("/(^|\s)'|'(\s|$)/u", ' ', $t);

        return array_values(array_filter(preg_split('/\s+/u', $t), fn ($w) => $w !== ''));
    }

    /**
     * @return array{equal: bool, similarity: float, differences: list<array{type: string, expected: string, found: string, context: string}>}
     */
    public function compare(string $approved, string $other): array
    {
        $a = $this->words($approved);
        $b = $this->words($other);
        $ops = $this->diff($a, $b);
        $diffs = [];
        $matched = 0;
        foreach ($ops as $op) {
            if ($op['type'] === 'equal') { $matched += count($op['a']); continue; }
            $diffs[] = [
                'type' => $op['type'], 'expected' => implode(' ', $op['a']), 'found' => implode(' ', $op['b']),
                'context' => implode(' ', array_slice($a, max(0, $op['ai'] - 4), 4 + count($op['a']) + 4)),
            ];
        }
        $total = max(count($a), count($b), 1);

        return ['equal' => $diffs === [], 'similarity' => round($matched / $total, 4), 'differences' => array_slice($diffs, 0, 30)];
    }

    /** LCS-based word diff grouped into equal / missing / extra / changed runs. */
    private function diff(array $a, array $b): array
    {
        $n = count($a); $m = count($b);
        if ($n * $m > 4_000_000) { // guard memory on huge inputs
            return $a === $b ? [['type' => 'equal', 'a' => $a, 'b' => $b, 'ai' => 0]] : [['type' => 'changed', 'a' => $a, 'b' => $b, 'ai' => 0]];
        }
        $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) for ($j = $m - 1; $j >= 0; $j--) $L[$i][$j] = $a[$i] === $b[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
        $ops = []; $i = 0; $j = 0; $cur = null;
        $push = function ($type, $wa, $wb, $ai) use (&$ops, &$cur) {
            if ($cur && ($cur['type'] === $type || ($cur['type'] !== 'equal' && $type !== 'equal'))) {
                if ($cur['type'] !== $type) $cur['type'] = 'changed';
                if ($wa !== null) $cur['a'][] = $wa;
                if ($wb !== null) $cur['b'][] = $wb;
                return;
            }
            if ($cur) $ops[] = $cur;
            $cur = ['type' => $type, 'a' => $wa !== null ? [$wa] : [], 'b' => $wb !== null ? [$wb] : [], 'ai' => $ai];
        };
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) { $push('equal', $a[$i], $b[$j], $i); $i++; $j++; }
            elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) { $push('missing', $a[$i], null, $i); $i++; }
            else { $push('extra', null, $b[$j], $i); $j++; }
        }
        while ($i < $n) { $push('missing', $a[$i], null, $i); $i++; }
        while ($j < $m) { $push('extra', null, $b[$j], $i); $j++; }
        if ($cur) $ops[] = $cur;

        return $ops;
    }
}

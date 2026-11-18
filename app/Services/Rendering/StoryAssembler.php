<?php

namespace App\Services\Rendering;

use App\Models\Project;
use App\Models\Scene;
use Illuminate\Support\Collection;

/**
 * Builds the story file for the page-load contract (core.js, bg.js, story, engine.js).
 * Each scene's code is isolated in its own function scope with SCENE_INDEX bound.
 */
class StoryAssembler
{
    /** @param Collection<int, Scene> $scenes ordered scenes with currentVersion loaded */
    public function story(Collection $scenes, array $opts = []): string
    {
        $director = file_get_contents(base_path('engine/kikitoons-compat/director.js'));
        $parts = ["/* MotionForge story file — generated ".now()->toIso8601String()." */", $director];
        $starts = [];
        $looks = [];
        $transitions = [];
        $offset = $opts['offset'] ?? (float) ($scenes->first()?->start_time ?? 0);
        foreach ($scenes->values() as $i => $scene) {
            $code = $scene->currentVersion?->code ?? 'function scene(ctx, t) { fillAll(ctx, "#000"); }';
            $parts[] = "const sc{$i} = (function () {\n  const SCENE_INDEX = {$i};\n{$code}\n  return scene;\n})();";
            $starts[] = round($scene->start_time - $offset, 3);
            $plan = $scene->plan();
            $looks[] = $plan['lighting'] ?? 'neutral';
            if ($i > 0) $transitions[] = $plan['transition'] ?? 'none';
        }
        $last = $scenes->last();
        $starts[] = round(($last?->end_time ?? 0) - $offset, 3);
        $story = [
            'starts' => $starts, 'transitions' => $transitions, 'looks' => $looks, 'narr' => [],
            'captionY' => (int) ($opts['caption_y'] ?? 1344), 'captionTheme' => 'serious', 'fps' => $opts['fps'] ?? 30,
            'noCaptions' => (bool) ($opts['no_captions'] ?? false), 'grain' => $opts['grain'] ?? 0.07,
        ];
        $scenesJs = '['.implode(', ', array_map(fn ($i) => "sc{$i}", array_keys($scenes->values()->all()))).']';
        $parts[] = 'const STORY = Object.assign('.json_encode($story).", { scenes: {$scenesJs} });";

        return implode("\n\n", $parts)."\n";
    }

    /** CAPS global: word timings (global seconds) with *keyword* / !slam markup from the plan. */
    public function caps(array $cues, Collection $scenes, float $offset = 0): string
    {
        $out = [];
        $slamDone = false;
        foreach ($cues as $cue) {
            $scene = $scenes->first(fn ($s) => $s->cue_start !== null && $cue['index'] >= $s->cue_start && $cue['index'] <= $s->cue_end);
            $plan = $scene?->plan() ?? [];
            $keywords = $plan['caption_keywords'] ?? [];
            $slam = $plan['slam_word'] ?? null;
            $tokens = preg_split('/\s+/u', trim($cue['text'])) ?: [];
            $weights = array_map(fn ($w) => max(2, mb_strlen($w)), $tokens);
            $total = array_sum($weights) ?: 1;
            $t = $cue['start'];
            $span = $cue['end'] - $cue['start'];
            $words = [];
            foreach ($tokens as $k => $tok) {
                $d = $span * $weights[$k] / $total;
                $norm = mb_strtolower(trim($tok, " \t.,!?;:\"'“”‘’…"));
                $kind = 'plain';
                if ($slam && ! $slamDone && $norm === $slam) { $kind = 'slam'; $slamDone = true; }
                elseif (in_array($norm, $keywords, true)) $kind = 'key';
                $words[] = ['w' => $tok, 'kind' => $kind, 'start' => round($t - $offset, 3), 'end' => round($t + $d - $offset, 3)];
                $t += $d;
            }
            $out[] = ['start' => round($cue['start'] - $offset, 3), 'end' => round($cue['end'] - $offset, 3), 'text' => $cue['text'], 'words' => $words];
        }

        return 'const CAPS = '.json_encode($out, JSON_UNESCAPED_UNICODE).";\n";
    }
}

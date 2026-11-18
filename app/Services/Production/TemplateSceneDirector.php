<?php

namespace App\Services\Production;

use App\Contracts\AnimationEngineInterface;
use App\Contracts\AnimationGeneratorInterface;
use App\Models\Character;
use App\Models\Scene;
use App\Models\User;
use App\Services\Production\Data\CodeResult;

/** Deterministic scene code from a plan, using the trusted mfStage() director runtime. */
class TemplateSceneDirector implements AnimationGeneratorInterface
{
    public function __construct(private AnimationEngineInterface $engine) {}

    public function generate(Scene $scene, array $plan, User $user, array $options = []): CodeResult
    {
        $code = $this->code($plan, $scene->duration(), $scene->sequence, $user);

        return new CodeResult($code, $this->engine->validateCode($code), 'template');
    }

    public function code(array $plan, float $duration, int $sequence, ?User $user = null): string
    {
        if (($plan['kind'] ?? 'story') === 'end_card') {
            $p = json_encode(['advice' => $plan['end_card']['advice'] ?? '', 'accent' => $plan['end_card']['accent'] ?? '', 'bg' => $this->bgKey($plan['background'] ?? 'bedroomNight'), 'dur' => round($duration, 3)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return "function scene(ctx, t) {\n  endCard(ctx, t, {$p});\n}\n";
        }
        $slamAt = null;
        $P = [
            'dur' => round($duration, 3), 'bg' => $this->bgKey($plan['background'] ?? 'hallway'), 'look' => $plan['lighting'] ?? 'neutral',
            'shot' => $plan['camera']['shot'] ?? 'medium', 'move' => $plan['camera']['movement'] ?? 'slow_push_in',
            'emotion' => $plan['emotion'] ?? 'neutral', 'actions' => array_values($plan['character_actions'] ?? []),
            'cast' => $this->cast($plan['characters'] ?? ['Kiki'], $user), 'seed' => $sequence + 1,
            'kw' => $plan['caption_keywords'][0] ?? null, 'slamAt' => $slamAt,
        ];
        $json = json_encode($P, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $slam = ! empty($plan['slam_word']) ? "\n  P.slamAt = capLocal(SCENE_INDEX, ".json_encode($plan['slam_word']).', null);' : '';

        return "function scene(ctx, t) {\n  const P = {$json};{$slam}\n  mfStage(ctx, t, P);\n}\n";
    }

    private function bgKey(string $bg): string
    {
        return in_array($bg, PlanVocabulary::BACKGROUNDS, true) ? $bg : 'hallway';
    }

    /** Map plan character names to library definitions. Unknown names become a generic kid or adult. */
    public function cast(array $names, ?User $user): array
    {
        $lib = $user ? Character::visibleTo($user)->get() : Character::whereNull('user_id')->get();
        $out = [];
        foreach (array_slice($names, 0, 3) as $name) {
            $n = mb_strtolower(trim((string) $name));
            if ($n === 'kiki') { $out[] = ['kind' => 'kiki', 'name' => 'Kiki']; continue; }
            $c = $lib->first(fn ($c) => mb_strtolower($c->name) === $n || $c->slug === $n);
            if ($c) {
                $out[] = ['kind' => $c->kind === 'suit' ? 'suit' : ($c->kind === 'kiki_preset' ? 'kiki' : 'kid'), 'name' => $c->name, 'def' => $c->definition ?: (object) []];
            } else {
                $adult = (bool) preg_match('/principal|teacher|dad|father|mom|mother|boss|man|woman|adult|mr|mrs|officer|coach/u', $n);
                $out[] = ['kind' => $adult ? 'suit' : 'kid', 'name' => (string) $name, 'def' => $adult ? ['suit' => '#3f4a5a', 'tie' => '#2d3a4a', 'hair' => 'grey'] : ['shirt' => '#5b6f9a', 'hair' => 'bob']];
            }
        }
        if (! collect($out)->contains('kind', 'kiki') && count($out) < 3) {
            array_unshift($out, ['kind' => 'kiki', 'name' => 'Kiki']);
        }

        return $out;
    }
}

<?php

namespace Tests\Feature;

use App\Services\Production\ActionLexicon;
use App\Services\Production\TemplateSceneDirector;
use Tests\TestCase;

class CharacterActionsTest extends TestCase
{
    public function test_narration_becomes_actions_in_spoken_order_with_their_trigger_words(): void
    {
        $actions = app(ActionLexicon::class)->extract('I grabbed my phone and read the message. Then I cried.');

        $this->assertSame(['phone', 'read', 'cry'], array_column($actions, 'key'));
        $this->assertSame(['phone', 'read', 'cried'], array_column($actions, 'word'));
    }

    public function test_a_specific_action_replaces_the_general_one_it_contains(): void
    {
        $actions = app(ActionLexicon::class)->extract('Kiki walked into the office and sat down.');

        $this->assertSame(['walk_in', 'sit'], array_column($actions, 'key'));
    }

    public function test_plan_phrases_from_any_source_map_to_director_actions(): void
    {
        $lexicon = app(ActionLexicon::class);

        $this->assertSame('phone', $lexicon->keyFor('takes out phone and scrolls'));
        $this->assertSame('phone', $lexicon->keyFor('looks at phone'));
        $this->assertSame('phone', $lexicon->keyFor('Watches a video on his phone'));
        $this->assertSame('eat', $lexicon->keyFor('eats breakfast'));
        $this->assertNull($lexicon->keyFor('holds a tense stare, breathing'));
    }

    public function test_other_characters_speak_only_when_the_narration_quotes_them(): void
    {
        $lexicon = app(ActionLexicon::class);

        $this->assertSame('shout', $lexicon->otherAction('Then the teacher yelled at the whole class.', ['Teacher']));
        $this->assertSame('talk', $lexicon->otherAction('Mr Sok quietly said no.', ['Mr Sok']));
        $this->assertSame('listen', $lexicon->otherAction('I walked past the office.', ['Mr Sok']));
    }

    public function test_scene_code_carries_timed_beats_and_falls_back_to_the_narration(): void
    {
        $director = app(TemplateSceneDirector::class);
        $plan = [
            'narration_reference' => 'I sat at my desk and typed the password.',
            'character_actions' => ['holds a tense stare, breathing'],
            'cast' => [['kind' => 'kiki', 'name' => 'Kiki']],
        ];

        $code = $director->code($plan, 4.0, 3);

        $this->assertStringContainsString('P.si = SCENE_INDEX;', $code);
        $this->assertSame([['a' => 'sit', 'w' => 'sat'], ['a' => 'type', 'w' => 'typed']], $this->stageParameters($code)['beats']);
    }

    public function test_plan_actions_are_timed_to_the_word_that_cues_them(): void
    {
        $beats = app(TemplateSceneDirector::class)->beats(['cries and wipes tears', 'takes out phone and scrolls'], 'She texted me and I started crying.');

        $this->assertSame([['a' => 'cry', 'w' => 'crying'], ['a' => 'phone', 'w' => 'texted']], $beats);
    }

    /** @return array<string, mixed> */
    private function stageParameters(string $code): array
    {
        $this->assertSame(1, preg_match('/const P = (\{.*\});/', $code, $match));

        return json_decode($match[1], true);
    }
}

<?php

namespace App\Services\Production;

/**
 * Closed vocabulary of character actions the template director can perform (engine/kikitoons-compat/director.js).
 * Turns narration or scene-sheet text into an ordered list of actions, each tied to the spoken word that
 * triggers it, so a character acts out what the voiceover is saying at the moment it is said.
 */
class ActionLexicon
{
    /**
     * Action key => [trigger pattern, editable phrase]. Order is priority: when two patterns match the same
     * words, the earlier (more specific) action wins.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const ACTIONS = [
        'walk_in' => ['\b(?:walked|walks|came|comes|ran|stormed|rushed|stepped)\s+(?:in|into|inside|through)\b|\b(?:entered|enters|arrived|arrives|showed up|came home|got home)\b', 'walks in'],
        'walk_away' => ['\b(?:walked|walks|ran|went|stormed|turned)\s+(?:away|off|out)\b|\bleft the\b', 'walks away'],
        'stand_up' => ['\b(?:stood|got|jumped|stand|stands)\s+up\b', 'stands up'],
        'look_around' => ['\b(?:looked|looks|look|glanced)\s+around\b|\b(?:searched|nobody|no one|empty|hid|hide|hiding|sneaked|snuck|someone|whispers?)\b', 'looks around nervously'],
        'knock' => ['\b(?:knocked|knock|knocks|knocking)\b', 'knocks on the door'],
        'call' => ['\b(?:called|calling|call|calls|dialed|dialled|rang|phoned|voicemail)\b', 'talks on the phone'],
        'hug' => ['\b(?:hugged|hug|hugs|hugging|embraced)\b', 'hugs'],
        'cry' => ['\b(?:cried|cry|cries|crying|tears|sobbed|sobbing|wept|weeping)\b', 'cries and wipes tears'],
        'laugh' => ['\b(?:laughed|laugh|laughs|laughing|giggled|smiled|smile|smiles|smiling|joked|funny)\b', 'laughs'],
        'shout' => ['\b(?:yelled|yell|yells|yelling|shouted|shout|shouts|screamed|scream|screaming|furious|angry)\b', 'shouts angrily'],
        'shock' => ['\b(?:shocked|froze|frozen|gasped|stunned|surprised|suddenly|terrified|scared|jumped)\b|couldn\'t believe', 'jumps back in shock'],
        'facepalm' => ['\b(?:stressed|panicked|panic|failed|fail|mistake|ruined|regret|regretted|headache|embarrassed|ashamed|desperate|worried)\b', 'holds head in hands'],
        'sleep' => ['\b(?:slept|sleep|sleeps|sleeping|asleep|nap|bedtime)\b', 'sleeps in bed'],
        'wake' => ['\b(?:woke|wake|wakes|waking|alarm)\b', 'wakes up and stretches'],
        'eat' => ['\b(?:ate|eat|eats|eating|lunch|dinner|breakfast|food|snack|meal|pizza|rice|noodles|hungry)\b', 'eats with a spoon'],
        'drink' => ['\b(?:drank|drink|drinks|drinking|coffee|water|tea|juice|milk|soda)\b', 'drinks from a cup'],
        'type' => ['\b(?:typed|typing|types|laptop|computer|keyboard|emails?|online|website|searched|googled|hacked|hack|coding|password)\b', 'types on a laptop'],
        'phone' => ['\b(?:phones?|texted|texts?|messages?|notifications?|scrolled|scrolling|scroll|screen|apps?|selfie|photos?|videos?|youtube|tiktok|instagram|chat|posted|followers)\b', 'takes out phone and scrolls'],
        'read' => ['\b(?:read|reads|reading|letter|books?|report|results|grades?|notes?|newspaper|article)\b', 'reads a paper'],
        'write' => ['\b(?:wrote|write|writes|writing|signed|homework|studied|study|studying|exam|essay|drew)\b', 'writes in a notebook'],
        'money' => ['\b(?:money|cash|paid|pay|pays|dollars?|bought|buy|price|cost|sold|sell|earned|saved|wallet|salary)\b', 'counts money'],
        'give' => ['\b(?:gave|give|gives|handed|offered|gift|present)\b', 'hands something over'],
        'door' => ['\b(?:doors?|opened|unlocked|slammed)\b', 'opens the door'],
        'wave' => ['\b(?:waved|wave|waves|hello|goodbye|bye|greeted)\b', 'waves'],
        'cheer' => ['\b(?:cheered|cheer|celebrated|celebrate|won|win|victory|proud)\b', 'cheers'],
        'run' => ['\b(?:ran|run|runs|running|rushed|hurried|sprinted|chased|raced)\b', 'runs in'],
        'sit' => ['\b(?:sat|sit|sits|sitting|seat|chair|couch|sofa)\b', 'sits down'],
        'pace' => ['\b(?:paced|pacing|waited|waiting|nervous)\b', 'paces back and forth'],
        'walk' => ['\b(?:walk|walks|walked|walking|went|headed|followed|stepped)\b', 'walks forward'],
        'shrug' => ['\b(?:shrugged|shrug|whatever|maybe|confused)\b|didn\'t know|no idea', 'shrugs'],
        'sigh' => ['\b(?:sighed|sigh|sad|alone|lonely|disappointed|tired|exhausted)\b', 'sighs, shoulders drop'],
        'nod' => ['\b(?:nodded|nod|nods|agreed|agree|yes|okay|promised)\b', 'nods'],
        'shake_head' => ['\b(?:shook|refused|denied|disagreed)\b', 'shakes head'],
        'point' => ['\b(?:pointed|points|point|accused|blamed|caught)\b', 'points'],
        'think' => ['\b(?:thought|think|thinks|thinking|realized|realised|wondered|wonder|remembered|remember|idea|decided|plan|planned|imagine|figured)\b', 'thinks, hand on chin'],
        'turn' => ['\b(?:looked|look|looks|turned|turns|stared|staring|noticed|saw|watched|glanced)\b', 'turns and looks'],
        'talk' => ['\b(?:said|says|say|told|tell|asked|ask|explained|replied|talked|talk|answered|spoke|speak|admitted|lied)\b', 'talks with hand gestures'],
    ];

    /** Actions that already include the motion of a weaker match on the same words. */
    private const SUPERSEDES = ['walk_in' => ['walk', 'run'], 'walk_away' => ['walk'], 'call' => ['phone'], 'knock' => ['door'], 'look_around' => ['turn']];

    /**
     * Actions in the order the text mentions them, each with the word that triggers it.
     *
     * @return list<array{key: string, phrase: string, word: string}>
     */
    public function extract(string $text, int $limit = 3): array
    {
        $text = $this->normalise($text);
        $claimed = [];
        $found = [];
        foreach (self::ACTIONS as $key => [$pattern, $phrase]) {
            if (! preg_match('/'.$pattern.'/iu', $text, $match, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            [$words, $offset] = $match[0];
            $end = $offset + strlen($words);
            $overlaps = collect($claimed)->contains(fn (array $range) => $offset < $range[1] && $end > $range[0]);
            $superseded = collect($found)->contains(fn (array $f) => in_array($key, self::SUPERSEDES[$f['key']] ?? [], true));
            if ($overlaps || $superseded) {
                continue;
            }
            $claimed[] = [$offset, $end];
            $found[] = ['key' => $key, 'phrase' => $phrase, 'word' => $this->firstWord($words), 'offset' => $offset];
        }
        usort($found, fn (array $a, array $b) => $a['offset'] <=> $b['offset']);

        return array_map(fn (array $f) => ['key' => $f['key'], 'phrase' => $f['phrase'], 'word' => $f['word']], array_slice($found, 0, $limit));
    }

    /** @return list<string> editable action phrases for a plan */
    public function phrases(string $text, int $limit = 3): array
    {
        return array_column($this->extract($text, $limit), 'phrase');
    }

    /** Action key for a plan phrase (ours, an AI's or a user's edit); null means "act out the emotion". */
    public function keyFor(string $phrase): ?string
    {
        $normalised = mb_strtolower(trim($phrase));
        foreach (self::ACTIONS as $key => [, $known]) {
            if ($normalised === $known) {
                return $key;
            }
        }
        foreach (self::ACTIONS as $key => [$pattern]) {
            if (preg_match('/'.$pattern.'/iu', $normalised)) {
                return $key; // a phrase describes one action: the most specific match wins ("looks at phone" → phone)
            }
        }

        return null;
    }

    /** First narration word that triggers the action, used to time the action to the voiceover. */
    public function triggerWord(string $key, string $narration): ?string
    {
        $pattern = self::ACTIONS[$key][0] ?? null;
        if (! $pattern || ! preg_match('/'.$pattern.'/iu', $this->normalise($narration), $match)) {
            return null;
        }

        return $this->firstWord($match[0]);
    }

    /**
     * What the other characters in the shot do: speak when the narration quotes them, otherwise listen.
     *
     * @param  list<string>  $names  other characters' names
     */
    public function otherAction(string $narration, array $names = []): string
    {
        $who = implode('|', array_merge(['he', 'she', 'they', 'teacher', 'mom', 'dad', 'mother', 'father', 'boss', 'friend', 'principal'], array_map(fn (string $n) => preg_quote(mb_strtolower($n), '/'), $names)));
        if (! preg_match('/\b(?:'.$who.')\s+(?:\w+\s+)?(said|asked|told|explained|replied|whispered|yelled|shouted|screamed|laughed|smiled)\b/iu', $this->normalise($narration), $m)) {
            return 'listen';
        }

        return match (mb_strtolower($m[1])) {
            'yelled', 'shouted', 'screamed' => 'shout',
            'laughed', 'smiled' => 'laugh',
            default => 'talk',
        };
    }

    private function normalise(string $text): string
    {
        return mb_strtolower(str_replace(['’', '‘'], "'", $text));
    }

    private function firstWord(string $words): string
    {
        return (string) preg_split('/\s+/u', trim($words))[0];
    }
}

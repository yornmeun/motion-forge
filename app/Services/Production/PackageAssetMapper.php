<?php

namespace App\Services\Production;

/**
 * Maps the package registries onto engine assets without AI: each CHAR_ ID becomes one fixed
 * character definition and each BG_ ID one engine background + light preset. The mapping is
 * deterministic, so the same ID looks the same in every scene (continuity).
 */
class PackageAssetMapper
{
    /** Muted wardrobe colours, assigned in registry order when the description names none. */
    private const SHIRTS = ['#7a5c8e', '#b85a4a', '#4f6f8f', '#5f7a5a', '#8a6a4a', '#5a5f7a'];

    private const COLOURS = [
        'black' => '#26262b', 'white' => '#e9e4da', 'grey' => '#6b6f78', 'gray' => '#6b6f78', 'red' => '#a8423a',
        'blue' => '#4f6f8f', 'navy' => '#2f3d5a', 'green' => '#5f7a5a', 'yellow' => '#c9a640', 'purple' => '#7a5c8e',
        'brown' => '#7a5a40', 'beige' => '#b8a586', 'pink' => '#c27a86', 'orange' => '#c7743a',
    ];

    /**
     * @param  array<string, array{id: string, name: string, text: string}>  $registry
     * @return array<string, array{kind: string, name: string, def: array<string, mixed>}>
     */
    public function characters(array $registry): array
    {
        $out = [];
        $female = 0;
        $male = 0;
        foreach (array_values($registry) as $i => $entry) {
            $text = mb_strtolower($entry['text']);
            $isFemale = (bool) preg_match('/\b(woman|girl|female|she|her|mother|mom|sister|wife|aunt|grandmother)\b/u', $text);
            $isSuit = (bool) preg_match('/\b(principal|boss|manager|businessman|businesswoman|officer|lawyer|ceo|director|in a suit)\b/u', $text);
            $isElder = (bool) preg_match('/\b(elderly|old man|old woman|grandfather|grandmother|grey-haired|gray-haired)\b/u', $text);
            $hairColour = $this->colourNear($text, ['hair']) ?? ($isElder ? '#9a9a9a' : (preg_match('/\b(blonde|blond)\b/u', $text) ? '#d8b25a' : '#1e1612'));
            $shirt = $this->colourNear($text, ['t-shirt', 'tee', 'shirt', 'top', 'jacket', 'hoodie', 'dress', 'blouse', 'sweater']) ?? self::SHIRTS[$i % count(self::SHIRTS)];
            if ($isSuit) {
                $out[$entry['id']] = ['kind' => 'suit', 'name' => $entry['name'], 'def' => ['suit' => $this->colourNear($text, ['suit']) ?? '#3f4a5a', 'tie' => '#2d3a4a', 'hair' => $isElder ? 'grey' : 'karl', 'glasses' => str_contains($text, 'glasses')]];

                continue;
            }
            $hair = $isFemale
                ? (['long', 'bob', 'pony', 'buns'][$female++ % 4])
                : (str_contains($text, 'curly') ? 'curly' : (['side', 'spiky'][$male++ % 2]));
            $out[$entry['id']] = ['kind' => 'kid', 'name' => $entry['name'], 'def' => array_filter([
                'shirt' => $shirt, 'hair' => $hair, 'hairCol' => $hairColour,
                'pants' => $this->colourNear($text, ['trousers', 'pants', 'jeans', 'skirt']),
            ])];
        }

        return $out;
    }

    /**
     * @param  array{id: string, name: string, text: string}  $entry
     * @return array{background: string, look: string}
     */
    public function background(array $entry): array
    {
        $text = mb_strtolower($entry['text']);
        $night = (bool) preg_match('/\b(night|midnight|dark)\b/u', $text);
        $background = match (true) {
            (bool) preg_match('/\bbedroom\b/u', $text) => $night ? 'bedroomNight' : 'bedroom',
            (bool) preg_match('/\b(café|cafe|coffee|restaurant|shop|store|bar|bakery|market)\b/u', $text) => 'shop',
            (bool) preg_match('/\b(kitchen|home|house|apartment|living room|sofa|dining)\b/u', $text) => 'kitchen',
            (bool) preg_match('/\b(office|workplace|meeting room|boss)\b/u', $text) => 'office',
            (bool) preg_match('/\b(classroom|class|lecture)\b/u', $text) => 'classroom',
            (bool) preg_match('/\b(hallway|corridor|school|locker)\b/u', $text) => 'hallway',
            (bool) preg_match('/\b(server|computer room|data)\b/u', $text) => 'serverRoom',
            (bool) preg_match('/\b(park|garden|playground|forest|tree)\b/u', $text) => 'park',
            (bool) preg_match('/\b(street|city|walkway|road|outdoor|sidewalk|town|bus)\b/u', $text) => $night ? 'streetNight' : 'street',
            default => $night ? 'bedroomNight' : 'kitchen',
        };
        $look = match (true) {
            $night => 'night',
            (bool) preg_match('/\bneutral\b/u', $text) => 'neutral',
            (bool) preg_match('/\b(golden|sunset|late-afternoon|late afternoon|warm)\b/u', $text) => 'warm',
            (bool) preg_match('/\b(cold|cool|blue|grey|gray|rain)\b/u', $text) => 'cold',
            (bool) preg_match('/\bevening\b/u', $text) => 'warm',
            default => 'neutral',
        };

        return ['background' => $background, 'look' => $look];
    }

    /** First colour word within a few words before or after one of the given nouns. */
    private function colourNear(string $text, array $nouns): ?string
    {
        $colours = implode('|', array_keys(self::COLOURS));
        foreach ($nouns as $noun) {
            $n = preg_quote($noun, '/');
            if (preg_match('/\b('.$colours.')\b(?:[\s\w-]{0,20}?)\b'.$n.'/u', $text, $m) || preg_match('/\b'.$n.'\b[\s\w,-]{0,12}?\b('.$colours.')\b/u', $text, $m)) {
                return self::COLOURS[$m[1]];
            }
        }

        return null;
    }
}

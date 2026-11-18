<?php

namespace Database\Seeders;

use App\Models\BackgroundAsset;
use App\Models\Character;
use Illuminate\Database\Seeder;

class LibrarySeeder extends Seeder
{
    public function run(): void
    {
        $chars = [
            ['kiki', 'Kiki', 'kiki_preset', 'The hero and narrator. Backwards red cap (brim left), freckles, yellow tee. Always drawn with the kiki() preset.', []],
            ['principal-karl', 'Principal Karl', 'suit', 'The school principal: brown hair, grey suit, red tie. Stern, controlling.', []],
            ['maya', 'Maya', 'kid', 'Kiki’s classmate: dark ponytail, teal shirt. Observant, loyal.', ['shirt' => '#3f7f7a', 'hair' => 'pony', 'hairCol' => '#2a1d18']],
            ['leo', 'Leo', 'kid', 'A rival kid: spiky hair, maroon shirt. Smug.', ['shirt' => '#7a3540', 'hair' => 'spiky', 'hairCol' => '#4a3424']],
            ['ms-grey', 'Ms Grey', 'suit', 'A teacher: grey bun, glasses, navy suit.', ['suit' => '#33415c', 'tie' => '#7a8aa6', 'hair' => 'bunGrey', 'glasses' => true]],
            ['dad', 'Dad', 'suit', 'Kiki’s father: grey hair, dark olive jacket, no tie colour.', ['suit' => '#4b5040', 'tie' => '#4b5040', 'hair' => 'grey']],
            ['officer', 'Officer', 'suit', 'A security officer: navy uniform and cap.', ['suit' => '#1f2a44', 'tie' => '#1f2a44', 'hair' => 'greycap']],
        ];
        foreach ($chars as [$slug, $name, $kind, $desc, $def]) {
            Character::updateOrCreate(['user_id' => null, 'slug' => $slug], ['name' => $name, 'kind' => $kind, 'description' => $desc, 'definition' => $def, 'is_builtin' => true]);
        }
        $bgs = [
            ['hallway', 'School hallway', 'hallway', 'Lockers converging to a door, flickering ceiling lights.', 'cold', 'hallway'],
            ['classroom', 'Classroom', 'BACKGROUNDS.classroom', 'Chalkboard, clock, posters, wooden floor.', 'neutral', 'room_tone'],
            ['office', 'Principal’s office', 'officeBg', 'Blinds with light shafts, shelves, diplomas, desk.', 'tense', 'office'],
            ['serverRoom', 'Server room', 'serverRoom', 'Racks with blinking status lights.', 'night', 'server'],
            ['street', 'Street (day)', 'street', 'City buildings and a road.', 'neutral', 'outdoors'],
            ['streetNight', 'Street (night)', 'street({night:true})', 'Lit windows and a flickering street lamp.', 'night', 'night'],
            ['kitchen', 'Home kitchen', 'kitchen', 'Tiles, cabinets and a counter.', 'warm', 'room_tone'],
            ['bedroom', 'Bedroom (day)', 'bedroom', 'Window, poster, bed.', 'neutral', 'room_tone'],
            ['bedroomNight', 'Bedroom (night)', 'bedroom({night:true})', 'Moonlit window and a warm lamp glow.', 'night', 'night'],
            ['park', 'Park', 'park', 'Trees, path and a bench.', 'neutral', 'outdoors'],
            ['shop', 'Shop', 'shop', 'Shelves of goods and a counter.', 'warm', 'room_tone'],
        ];
        foreach ($bgs as [$slug, $name, $fn, $desc, $look, $amb]) {
            BackgroundAsset::updateOrCreate(['slug' => $slug], ['name' => $name, 'engine_function' => $fn, 'description' => $desc, 'default_look' => $look, 'ambience' => $amb, 'is_builtin' => true]);
        }
    }
}

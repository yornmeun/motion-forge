<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Services\Rendering\KikitoonsEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Reusable side characters, built from the engine's drawChar/drawSuit parameters (Kiki is the fixed preset). */
class CharacterController extends Controller
{
    public function index(Request $request): View
    {
        return view('library.characters', ['characters' => Character::visibleTo($request->user())->orderByDesc('is_builtin')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::notIn(['Kiki', 'kiki', 'Kid', 'Adult'])],
            'kind' => ['required', Rule::in(['kid', 'suit'])],
            'description' => ['nullable', 'string', 'max:300'],
            'shirt' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'suit' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'tie' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'hair' => ['required', Rule::in(['bob', 'pony', 'buns', 'long', 'side', 'spiky', 'curly', 'beanie', 'karl', 'grey', 'greycap', 'bunGrey'])],
            'hairCol' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'], 'glasses' => ['boolean'], 'mustache' => ['boolean'],
        ]);
        $def = $d['kind'] === 'kid'
            ? array_filter(['shirt' => $d['shirt'] ?? '#5b6f9a', 'hair' => $d['hair'], 'hairCol' => $d['hairCol'] ?? null])
            : array_filter(['suit' => $d['suit'] ?? '#3f4a5a', 'tie' => $d['tie'] ?? '#2d3a4a', 'hair' => $d['hair'], 'hairCol' => $d['hairCol'] ?? null, 'glasses' => $request->boolean('glasses') ?: null, 'mustache' => $request->boolean('mustache') ?: null]);
        Character::create(['user_id' => $request->user()->id, 'slug' => Str::slug($d['name']).'-'.Str::lower(Str::random(4)), 'name' => $d['name'], 'kind' => $d['kind'], 'description' => $d['description'] ?? null, 'definition' => $def]);

        return back()->with('status', "{$d['name']} added to your library.");
    }

    public function destroy(Request $request, Character $character): RedirectResponse
    {
        abort_unless($character->user_id === $request->user()->id && ! $character->is_builtin, 403);
        $character->delete();

        return back()->with('status', 'Character removed.');
    }

    /** Sandboxed stills sheet (front + expressions) rendered client-side by the engine. */
    public function preview(Request $request, Character $character, KikitoonsEngine $engine): Response
    {
        abort_unless($character->user_id === null || $character->user_id === $request->user()->id, 403);
        $def = json_encode($character->definition ?: (object) []);
        $kind = json_encode($character->kind);
        $dir = $engine->engineDir();
        $font = base64_encode(file_get_contents(config('motionforge.font')));
        $js = file_get_contents($dir.'/core.js')."\n".file_get_contents($dir.'/bg.js');
        $html = <<<HTML
<!doctype html><html><head><meta charset="utf-8"><style>@font-face{font-family:Poppins;font-weight:700;src:url(data:font/ttf;base64,{$font})}html,body{margin:0;background:#1B2435}canvas{width:100%;height:auto;display:block}</style></head>
<body><canvas id="c" width="1600" height="700"></canvas><script>{$js}</script><script>
const ctx = document.getElementById('c').getContext('2d'), D = {$def}, K = {$kind};
ctx.fillStyle = '#1B2435'; ctx.fillRect(0, 0, 1600, 700);
const faces = [{brows:'flat',mouth:'flat'},{brows:'stern',mouth:'flat',lid:0.2},{brows:'worried',mouth:'wavy'},{brows:'up',mouth:'O',ma:0.6},{brows:'angry',mouth:'teeth'}];
const labels = ['neutral','determined','worried','shocked','angry'];
faces.forEach((f, i) => {
  const x = 180 + i * 310;
  if (K === 'kiki_preset') drawChar(ctx, kiki({x, y: 230, s: 0.85, face: f}));
  else if (K === 'suit') drawSuit(ctx, Object.assign({}, D, {x, y: 180, s: 0.8, face: f}));
  else drawChar(ctx, Object.assign({shirt:'#5b6f9a'}, D, {x, y: 230, s: 0.85, face: f}));
  ctx.setTransform(1,0,0,1,0,0); txt(ctx, labels[i].toUpperCase(), x, 660, 30, '#94A3B8');
});
</script></body></html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Content-Security-Policy' => "sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; font-src data:"]);
    }
}

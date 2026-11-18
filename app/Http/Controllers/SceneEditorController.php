<?php

namespace App\Http\Controllers;

use App\Jobs\ValidateSceneCodeJob;
use App\Models\AiCredential;
use App\Models\Character;
use App\Models\Project;
use App\Models\Scene;
use App\Models\SceneAsset;
use App\Models\SceneVersion;
use App\Services\Production\LockedNarrationException;
use App\Services\Production\PlanVocabulary;
use App\Services\Production\ProductionWorkflow;
use App\Services\Production\SrtParser;
use App\Services\Production\StoryboardService;
use App\Services\Rendering\KikitoonsEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class SceneEditorController extends Controller
{
    public function picker(Request $request): View
    {
        return view('editor.picker', ['projects' => $request->user()->projects()->whereHas('scenes')->latest('updated_at')->get()]);
    }

    public function show(Request $request, Project $project): View
    {
        Gate::authorize('view', $project);

        return view('editor.show', ['project' => $project, 'canEdit' => Gate::allows('update', $project)]);
    }

    public function state(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);
        $scenes = $project->scenes()->with(['versions', 'assets'])->get();
        $package = $project->approvedPackage;
        $cues = $package?->srt_text ? app(SrtParser::class)->parse($package->srt_text) : [];

        return response()->json([
            'project' => [
                'id' => $project->id, 'title' => $project->title, 'mode' => $project->production_mode->value, 'strategy' => $project->generation_strategy,
                'duration' => (float) ($scenes->last()?->end_time ?? 0), 'fps' => $project->frame_rate,
                'voice' => $package?->voiceover ? route('media.show', [$project, 'file', $package->voiceover_file_id]) : null,
            ],
            'cues' => $cues,
            'scenes' => $scenes->map(fn (Scene $s) => [
                'id' => $s->id, 'sequence' => $s->sequence, 'start' => $s->start_time, 'end' => $s->end_time, 'cue_start' => $s->cue_start, 'cue_end' => $s->cue_end,
                'kind' => $s->kind, 'source' => $s->source, 'status' => $s->status, 'error' => $s->error,
                'narration' => $s->narration_reference, 'current' => $s->current_version_id,
                'credential' => $s->ai_credential_id, 'model' => $s->ai_model,
                'plan' => $s->versions->firstWhere('id', $s->current_version_id)?->plan ?? [],
                'code' => $s->versions->firstWhere('id', $s->current_version_id)?->code,
                'validation' => $s->versions->firstWhere('id', $s->current_version_id)?->code_validation,
                'versions' => $s->versions->map(fn (SceneVersion $v) => ['id' => $v->id, 'version' => $v->version, 'origin' => $v->origin, 'notes' => $v->notes, 'valid' => $v->codeIsValid(), 'has_code' => $v->code !== null, 'at' => $v->created_at->diffForHumans()])->values(),
                'preview' => ($a = $s->assets->first(fn ($a) => $a->type === 'preview_png' && $a->scene_version_id === $s->current_version_id)) ? route('media.show', [$project, 'scene-asset', $a->id]).'?v='.$a->updated_at->timestamp : null,
                'clips' => $s->assets->where('type', 'ai_video')->map(fn ($a) => ['id' => $a->id, 'status' => $a->status, 'origin' => $a->origin, 'prompt' => $a->metadata['prompt'] ?? null, 'url' => in_array($a->status, ['ready', 'approved'], true) ? route('media.show', [$project, 'scene-asset', $a->id]) : null])->values(),
                'player' => route('scenes.player', [$project, $s]),
            ])->values(),
            'options' => [
                'shots' => PlanVocabulary::SHOTS, 'moves' => PlanVocabulary::MOVES, 'looks' => PlanVocabulary::LOOKS, 'emotions' => PlanVocabulary::EMOTIONS,
                'transitions' => PlanVocabulary::TRANSITIONS, 'backgrounds' => PlanVocabulary::BACKGROUNDS, 'sfx' => PlanVocabulary::SFX, 'music' => PlanVocabulary::MUSIC,
                'characters' => Character::visibleTo($request->user())->pluck('name')->merge(['Kid', 'Adult'])->values(),
                'credentials' => AiCredential::with('provider')->visibleTo($request->user())->get()->map(fn ($c) => ['id' => $c->id, 'name' => $c->name.' — '.$c->provider->name, 'usable' => $c->isUsable()]),
            ],
        ]);
    }

    public function update(Request $request, Project $project, Scene $scene, StoryboardService $boards, ProductionWorkflow $workflow): JsonResponse
    {
        Gate::authorize('update', $project);
        abort_unless($scene->project_id === $project->id, 404);
        $in = fn (array $v) => Rule::in($v);
        $d = $request->validate([
            'plan' => ['array'],
            'plan.visual_description' => ['sometimes', 'string', 'max:600'],
            'plan.video_prompt' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'plan.characters' => ['sometimes', 'array', 'max:3'], 'plan.characters.*' => ['string', 'max:60'],
            'plan.character_actions' => ['sometimes', 'array', 'max:4'], 'plan.character_actions.*' => ['string', 'max:120'],
            'plan.emotion' => ['sometimes', $in(PlanVocabulary::EMOTIONS)],
            'plan.camera.shot' => ['sometimes', $in(PlanVocabulary::SHOTS)], 'plan.camera.movement' => ['sometimes', $in(PlanVocabulary::MOVES)],
            'plan.lighting' => ['sometimes', $in(PlanVocabulary::LOOKS)], 'plan.background' => ['sometimes', $in(PlanVocabulary::BACKGROUNDS)],
            'plan.sound_effects' => ['sometimes', 'array', 'max:4'], 'plan.sound_effects.*' => [$in(PlanVocabulary::SFX)],
            'plan.music' => ['sometimes', $in(PlanVocabulary::MUSIC)], 'plan.transition' => ['sometimes', $in(PlanVocabulary::TRANSITIONS)],
            'plan.caption_keywords' => ['sometimes', 'array', 'max:2'], 'plan.caption_keywords.*' => ['string', 'max:30'],
            'plan.end_card.advice' => ['sometimes', 'string', 'min:10', 'max:120'], 'plan.end_card.accent' => ['sometimes', 'nullable', 'string', 'max:30'],
            'plan.narration_reference' => ['sometimes', 'string'],
            'ai_credential_id' => ['sometimes', 'nullable', Rule::in(AiCredential::visibleTo($request->user())->pluck('id')->all())],
            'ai_model' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        try {
            if (array_key_exists('ai_credential_id', $d) || array_key_exists('ai_model', $d)) {
                $scene->update(collect($d)->only(['ai_credential_id', 'ai_model'])->all());
            }
            $version = null;
            if (! empty($d['plan'])) {
                $plan = $d['plan'];
                if (isset($plan['caption_keywords'])) {
                    $words = app(\App\Services\Production\NarrationMatcher::class)->words((string) $scene->narration_reference);
                    foreach ($plan['caption_keywords'] as $k) {
                        abort_unless(in_array(mb_strtolower(trim($k, ' *!.,')), $words, true), 422, "“{$k}” is not spoken in this scene.");
                    }
                }
                $version = $boards->edit($scene, $plan, null, $request->user(), 'manual', 'Edited in scene editor');
                // template scenes are re-staged immediately (no AI cost); AI scenes need an explicit regenerate
                if ($scene->source === 'canvas' && ($project->generation_strategy === 'template' || $scene->kind === 'end_card')) {
                    $workflow->regenerateScene($scene->fresh(), $request->user(), ['strategy' => 'template']);
                }
            }
        } catch (LockedNarrationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'version' => $version?->id]);
    }

    public function regenerate(Request $request, Project $project, Scene $scene, ProductionWorkflow $workflow): JsonResponse
    {
        Gate::authorize('produce', $project);
        abort_unless($scene->project_id === $project->id, 404);
        $d = $request->validate(['direction' => ['nullable', 'string', 'max:800'], 'approved' => ['boolean'], 'strategy' => ['nullable', Rule::in(['ai', 'template'])], 'prompt' => ['nullable', 'string', 'max:2000']]);
        try {
            $job = $workflow->regenerateScene($scene, $request->user(), array_filter($d, fn ($v) => $v !== null && $v !== ''));
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['job' => $job->id]);
    }

    /** Undo / redo: point the scene at another stored version. */
    public function revert(Request $request, Project $project, Scene $scene): JsonResponse
    {
        Gate::authorize('update', $project);
        $d = $request->validate(['version_id' => ['required', 'string']]);
        $v = $scene->versions()->whereKey($d['version_id'])->firstOrFail();
        $scene->update(['current_version_id' => $v->id, 'status' => $v->codeIsValid() ? ($scene->assets()->where('type', 'preview_png')->where('scene_version_id', $v->id)->exists() ? 'preview_ready' : 'generated') : 'planned', 'error' => null]);
        if ($v->codeIsValid() && ! $scene->assets()->where('type', 'preview_png')->where('scene_version_id', $v->id)->exists()) {
            \App\Jobs\GenerateScenePreviewJob::dispatch($scene->id);
        }

        return response()->json(['ok' => true]);
    }

    public function timing(Request $request, Project $project, Scene $scene, StoryboardService $boards): JsonResponse
    {
        Gate::authorize('update', $project);
        $d = $request->validate(['cue_end' => ['required', 'integer', 'min:0']]);
        try {
            $boards->moveBoundary($scene, $d['cue_end'], $request->user());
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function code(Request $request, Project $project, Scene $scene, StoryboardService $boards): JsonResponse
    {
        Gate::authorize('update', $project);
        $d = $request->validate(['code' => ['required', 'string', 'max:60000']]);
        $v = $boards->edit($scene, [], $d['code'], $request->user(), 'manual', 'Code edited manually');
        $scene->update(['status' => 'generating']);
        ValidateSceneCodeJob::dispatch($v->id);

        return response()->json(['ok' => true, 'version' => $v->id]);
    }

    public function source(Request $request, Project $project, Scene $scene): JsonResponse
    {
        Gate::authorize('update', $project);
        abort_unless($project->production_mode->value === 'hybrid', 422, 'Only hybrid projects mix Canvas and AI video scenes.');
        $d = $request->validate(['source' => ['required', Rule::in(['canvas', 'ai_video'])]]);
        $scene->update(['source' => $d['source']]);

        return response()->json(['ok' => true]);
    }

    public function clipDecision(Request $request, Project $project, Scene $scene, SceneAsset $asset): JsonResponse
    {
        Gate::authorize('update', $project);
        abort_unless($asset->scene_id === $scene->id && $scene->project_id === $project->id && $asset->type === 'ai_video', 404);
        $d = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])]]);
        if ($d['decision'] === 'approve') {
            abort_unless(in_array($asset->status, ['ready', 'approved'], true), 422, 'Only finished clips can be approved.');
            $scene->assets()->where('type', 'ai_video')->where('status', 'approved')->update(['status' => 'ready', 'approved_at' => null]);
            $asset->update(['status' => 'approved', 'approved_at' => now()]);
            $scene->update(['status' => 'generated']);
        } else {
            $asset->update(['status' => 'rejected']);
        }

        return response()->json(['ok' => true]);
    }

    public function reorder(Request $request, Project $project, StoryboardService $boards): JsonResponse
    {
        Gate::authorize('update', $project);
        $d = $request->validate(['order' => ['required', 'array'], 'order.*' => ['string']]);
        $errors = $boards->validateOrder($project, $d['order']);
        if ($errors) {
            return response()->json(['message' => implode(' ', $errors)], 422);
        }
        if ($project->production_mode->requiresLockedPackage() && $project->approvedPackage) {
            return response()->json(['ok' => true, 'message' => 'Order is valid; narrated scenes keep their approved order.']);
        }
        // native-video scenes: re-sequence and re-time contiguously
        $t = 0.0;
        foreach ($d['order'] as $i => $id) {
            $s = $project->scenes()->whereKey($id)->first();
            $dur = $s->duration();
            $s->update(['sequence' => $i + 1, 'start_time' => round($t, 3), 'end_time' => round($t + $dur, 3)]);
            $t += $dur;
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Sandboxed live preview. The response carries a `sandbox allow-scripts` CSP, so even if opened
     * directly it runs in an opaque origin with no network, no eval and no access to MotionForge.
     */
    public function player(Request $request, Project $project, Scene $scene, KikitoonsEngine $engine): Response
    {
        Gate::authorize('view', $project);
        abort_unless($scene->project_id === $project->id, 404);
        $scene->load('currentVersion');
        if ($request->filled('version')) {
            $v = $scene->versions()->whereKey($request->string('version'))->firstOrFail();
            $scene->setRelation('currentVersion', $v);
        }
        $work = $engine->tmp('player');
        try {
            $files = $engine->writeStory(collect([$scene]), $project, $work);
            $parts = [
                file_get_contents($engine->engineDir().'/core.js'), file_get_contents($engine->engineDir().'/bg.js'),
                $files['caps'] ? file_get_contents($files['caps']) : '', file_get_contents($files['story']), file_get_contents($engine->engineDir().'/engine.js'),
            ];
        } finally {
            $engine->cleanup($work);
        }
        $font = base64_encode(file_get_contents(config('motionforge.font')));
        $scripts = implode("\n", array_map(fn ($p) => '<script>'.str_ireplace('</script', '<\/script', $p).'</script>', $parts));
        $html = <<<HTML
<!doctype html><html><head><meta charset="utf-8">
<style>@font-face{font-family:Poppins;font-weight:700;src:url(data:font/ttf;base64,{$font}) format('truetype')}
html,body{margin:0;height:100%;background:#000;overflow:hidden}canvas{height:100%;width:auto;display:block;margin:0 auto;max-width:100%;object-fit:contain}</style></head>
<body><canvas id="stage" width="1080" height="1920"></canvas>
{$scripts}
<script>
(function(){
  var t = 0, playing = false, last = 0, D = (window.MF && MF.duration) || 0;
  function post(m){ parent.postMessage(Object.assign({source:'mf-player'}, m), '*'); }
  function draw(){ try { MF.frame(Math.min(t, Math.max(0, D - 0.001))); } catch(e) { post({type:'error', message:String(e.message||e)}); } if (MF.errors.length) post({type:'error', message: 'scene threw: ' + MF.errors[0].message}); post({type:'time', t:t, duration:D}); }
  function loop(ts){ if (playing) { if (last) t += (ts - last) / 1000; last = ts; if (t >= D) { t = 0; } draw(); } requestAnimationFrame(loop); }
  window.addEventListener('message', function(e){ var m = e.data || {}; if (m.cmd === 'play') { playing = true; last = 0; } if (m.cmd === 'pause') { playing = false; } if (m.cmd === 'seek') { t = Math.max(0, Math.min(D, +m.t || 0)); draw(); } });
  document.fonts.ready.then(function(){ draw(); post({type:'ready', duration:D}); requestAnimationFrame(loop); });
})();
</script></body></html>
HTML;

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "sandbox allow-scripts; default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; font-src data:; img-src data:",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

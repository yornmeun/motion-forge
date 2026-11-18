<?php

namespace App\Http\Controllers;

use App\Enums\AICapability;
use App\Exceptions\AIProviderException;
use App\Exceptions\BudgetExceededException;
use App\Models\AiCredential;
use App\Services\AI\AIManager;
use App\Services\AI\ModelRegistry;
use App\Services\AI\ProviderRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Playground: send a prompt to a connected text model through the same router, budget and usage logging. */
class AiStudioController extends Controller
{
    public function index(Request $request, ModelRegistry $registry): View
    {
        $creds = AiCredential::with('provider')->visibleTo($request->user())->get();

        return view('studio.index', [
            'credentials' => $creds->map(fn ($c) => ['id' => $c->id, 'name' => $c->name.' — '.$c->provider->name, 'usable' => $c->isUsable(), 'models' => $registry->modelsFor(AICapability::Text, $c->provider->slug)->pluck('model_id')]),
            'recent' => \App\Models\AiRequest::where('user_id', $request->user()->id)->where('request_type', 'studio')->latest()->take(8)->get(),
        ]);
    }

    public function run(Request $request, AIManager $ai, ProviderRouter $router): JsonResponse
    {
        $d = $request->validate([
            'credential_id' => ['required', Rule::in(AiCredential::visibleTo($request->user())->pluck('id')->all())],
            'model' => ['nullable', 'string', 'max:120'],
            'system' => ['nullable', 'string', 'max:4000'],
            'prompt' => ['required', 'string', 'max:20000'],
            'approved' => ['boolean'],
        ]);
        try {
            $route = $router->resolve($request->user(), AICapability::Text, $d['credential_id'], $d['model'] ?? null);
            $r = $ai->call($route, $request->user(), AICapability::Text, 'studio', ['system' => $d['system'] ?? null, 'messages' => [['role' => 'user', 'content' => $d['prompt']]]], null, approvedExpensive: $request->boolean('approved'), expectedOutputTokens: 2000, options: ['max_tokens' => 4000]);
            $req = \App\Models\AiRequest::find($r->meta['request_id'] ?? null);
        } catch (BudgetExceededException $e) {
            return response()->json(['message' => $e->getMessage(), 'needs_approval' => true], 409);
        } catch (AIProviderException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['text' => $r->text, 'route' => $route->label(), 'input_tokens' => $r->inputTokens, 'output_tokens' => $r->outputTokens, 'cost' => $req?->estimated_cost_usd, 'duration_ms' => $req?->duration_ms]);
    }
}

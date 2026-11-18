<?php

namespace App\Http\Controllers;

use App\Enums\ProviderStatus;
use App\Exceptions\AIProviderException;
use App\Models\AiCredential;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Services\AI\AIManager;
use App\Services\AI\ModelRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * AI provider connections. Keys are encrypted at rest, never rendered back, and only used
 * server-side. Provider endpoints are fixed in config (no user-supplied URLs, so no SSRF surface).
 * A connection is marked "connected" only after a real authenticated request succeeds.
 */
class CredentialController extends Controller
{
    public function providers(Request $request): View
    {
        return view('settings.providers', $this->data($request));
    }

    public function index(Request $request): View
    {
        return view('settings.credentials', $this->data($request));
    }

    private function data(Request $request): array
    {
        $user = $request->user();

        return [
            'providers' => AiProvider::where('is_active', true)->orderBy('name')->get(),
            'credentials' => AiCredential::with('provider')->visibleTo($user)->orderBy('created_at')->get(),
            'models' => AiModel::with('provider')->where('is_active', true)->get()->groupBy(fn ($m) => $m->provider->slug),
            'usage' => \App\Models\AiRequest::selectRaw('ai_credential_id, count(*) as n, sum(estimated_cost_usd) as est, sum(confirmed_cost_usd) as conf')
                ->where('user_id', $user->id)->where('created_at', '>=', now()->startOfMonth())->groupBy('ai_credential_id')->get()->keyBy('ai_credential_id'),
        ];
    }

    public function store(Request $request, AIManager $ai, ModelRegistry $registry): RedirectResponse
    {
        Gate::authorize('manage-credentials');
        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(config('motionforge.providers')))],
            'name' => ['required', 'string', 'max:80'],
            'api_key' => ['required', 'string', 'min:12', 'max:400', 'regex:/^[\x21-\x7E]+$/'],
            'monthly_limit_usd' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ], ['api_key.regex' => 'The API key contains invalid characters.']);
        $provider = AiProvider::where('slug', $data['provider'])->firstOrFail();
        $credential = AiCredential::create([
            'user_id' => $request->user()->id, 'ai_provider_id' => $provider->id, 'name' => $data['name'],
            'secret' => trim($data['api_key']), 'key_hint' => AiCredential::hintFor(trim($data['api_key'])),
            'status' => ProviderStatus::Unverified, 'monthly_limit_usd' => $data['monthly_limit_usd'] ?? null,
        ]);
        $result = $this->runTest($credential, $ai, $registry);

        return redirect()->route('providers.index')->with('status', $result);
    }

    public function test(Request $request, AiCredential $credential, AIManager $ai, ModelRegistry $registry): RedirectResponse
    {
        Gate::authorize('use', $credential);

        return back()->with('status', $this->runTest($credential, $ai, $registry));
    }

    private function runTest(AiCredential $credential, AIManager $ai, ModelRegistry $registry): string
    {
        $key = $credential->apiKey();
        if (! $key) {
            $credential->update(['status' => ProviderStatus::Invalid, 'status_message' => 'No key available.', 'last_tested_at' => now()]);

            return "“{$credential->name}”: no key is available to test.";
        }
        try {
            $res = $ai->provider($credential->provider->slug)->testConnection($key);
            $new = $registry->recordDiscovered($credential->provider, $res['models'] ?? []);
            $credential->update(['status' => ProviderStatus::Connected, 'status_message' => $res['message'], 'last_tested_at' => now()]);

            return "“{$credential->name}” connected. {$res['message']}".($new ? " {$new} new model(s) discovered (capabilities unverified)." : '');
        } catch (AIProviderException $e) {
            $status = $e->kind === 'auth' ? ProviderStatus::Invalid : ProviderStatus::Error;
            $credential->update(['status' => $status, 'status_message' => AIProviderException::scrub($e->getMessage(), $key), 'last_tested_at' => now()]);
            Log::info('Credential test failed', ['credential' => $credential->id, 'kind' => $e->kind, 'status' => $e->status]);

            return "“{$credential->name}” is not connected: ".AIProviderException::scrub($e->getMessage(), $key);
        }
    }

    public function update(Request $request, AiCredential $credential, AIManager $ai, ModelRegistry $registry): RedirectResponse
    {
        Gate::authorize('manage', $credential);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'default_model' => ['nullable', 'string', 'max:120'],
            'is_enabled' => ['sometimes', 'boolean'],
            'monthly_limit_usd' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'api_key' => ['nullable', 'string', 'min:12', 'max:400', 'regex:/^[\x21-\x7E]+$/'],
        ]);
        if (! empty($data['default_model'])) {
            $exists = AiModel::where('ai_provider_id', $credential->ai_provider_id)->where('model_id', $data['default_model'])->exists();
            abort_unless($exists, 422, 'Unknown model for this provider.');
        }
        $rotated = ! empty($data['api_key']);
        if ($rotated && $credential->isServerManaged()) {
            abort(422, 'Server-managed keys are rotated in the server environment.');
        }
        $credential->fill(collect($data)->except('api_key')->all());
        if ($rotated) {
            $credential->fill(['secret' => trim($data['api_key']), 'key_hint' => AiCredential::hintFor(trim($data['api_key'])), 'rotated_at' => now(), 'status' => ProviderStatus::Unverified]);
        }
        $credential->save();
        $msg = $rotated ? $this->runTest($credential, $ai, $registry) : "“{$credential->name}” updated.";

        return back()->with('status', $msg);
    }

    public function destroy(Request $request, AiCredential $credential): RedirectResponse
    {
        Gate::authorize('manage', $credential);
        $name = $credential->name;
        $credential->delete(); // projects referencing it are set to null by FK; request history keeps provider + model

        return back()->with('status', "“{$name}” revoked and its key deleted.");
    }
}

<?php

namespace App\Services\AI;

use App\Enums\AICapability;
use App\Exceptions\AIProviderException;
use App\Models\AiCredential;
use App\Models\Project;
use App\Models\User;

/**
 * Chooses a configured credential + model for a capability.
 *  - Manual selection is validated against the capability registry.
 *  - Automatic selection uses only connected, enabled credentials visible to the user.
 *  - Fallback is returned only when the project explicitly enables it.
 */
class ProviderRouter
{
    public function __construct(private AIManager $ai, private ModelRegistry $registry) {}

    public function resolve(User $user, AICapability $capability, ?string $credentialId = null, ?string $modelId = null): Route
    {
        if ($credentialId) {
            $credential = AiCredential::with('provider')->visibleTo($user)->find($credentialId);
            if (! $credential) {
                throw new AIProviderException('The selected AI connection was not found or is not available to you.', 'unsupported');
            }
            if (! $credential->isUsable()) {
                throw new AIProviderException("The connection \"{$credential->name}\" is not connected. Test it in Settings › AI Providers.", 'auth');
            }
            $modelId = $modelId ?: $credential->default_model;
            if (! $modelId) {
                $first = $this->registry->modelsFor($capability, $credential->provider->slug)->first();
                $modelId = $first?->model_id;
            }
            $model = $modelId ? $this->registry->find($credential->provider->slug, $modelId) : null;
            if (! $model || ! $model->supports($capability)) {
                throw new AIProviderException(sprintf('Model "%s" on %s is not registered for %s.', $modelId ?? '—', $credential->provider->name, $capability->label()), 'unsupported');
            }

            return new Route($credential, $model, $this->ai->provider($credential->provider->slug));
        }

        // automatic: prefer each credential's default model, then any registry model with the capability
        $credentials = AiCredential::with('provider')->visibleTo($user)->where('is_enabled', true)->where('status', 'connected')->orderByRaw('user_id is null')->get();
        foreach ($credentials as $credential) {
            $slug = $credential->provider->slug;
            $candidates = collect([$credential->default_model])->filter()
                ->map(fn ($id) => $this->registry->find($slug, $id))
                ->filter(fn ($m) => $m && $m->supports($capability));
            $model = $candidates->first() ?? $this->registry->modelsFor($capability, $slug)->first();
            if ($model) {
                return new Route($credential, $model, $this->ai->provider($slug));
            }
        }

        throw new AIProviderException('No connected AI provider supports '.$capability->label().'. Add and test a connection in Settings › AI Providers.', 'unsupported');
    }

    public function forProject(Project $project, User $user, AICapability $capability, ?string $credentialId = null, ?string $modelId = null): Route
    {
        if ($capability === AICapability::NativeVideo) {
            return $this->resolve($user, $capability, $credentialId ?? $project->video_credential_id, $modelId ?? $project->video_model);
        }

        return $this->resolve($user, $capability, $credentialId ?? $project->ai_credential_id, $modelId ?? $project->ai_model);
    }

    public function fallback(Project $project, User $user, AICapability $capability): ?Route
    {
        if (! $project->fallback_enabled || ! $project->fallback_credential_id) {
            return null;
        }
        try {
            $route = $this->resolve($user, $capability, $project->fallback_credential_id, $project->fallback_model);

            return new Route($route->credential, $route->model, $route->provider, true);
        } catch (AIProviderException) {
            return null;
        }
    }
}

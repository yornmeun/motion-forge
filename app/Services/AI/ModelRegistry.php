<?php

namespace App\Services\AI;

use App\Enums\AICapability;
use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Support\Collection;

/**
 * Capability registry. Only models listed in the registry (seeded from config/motionforge.php,
 * editable by admins) are routable. Models discovered through a provider's list endpoint are stored
 * with is_registry = false and no capabilities, so they are never assumed to support anything.
 */
class ModelRegistry
{
    public function find(string $providerSlug, string $modelId): ?AiModel
    {
        return AiModel::query()->whereHas('provider', fn ($q) => $q->where('slug', $providerSlug))->where('model_id', $modelId)->first();
    }

    public function supports(string $providerSlug, string $modelId, AICapability $capability): bool
    {
        return (bool) $this->find($providerSlug, $modelId)?->supports($capability);
    }

    /** @return Collection<int, AiModel> */
    public function modelsFor(AICapability $capability, ?string $providerSlug = null): Collection
    {
        return AiModel::with('provider')->where('is_active', true)
            ->when($providerSlug, fn ($q) => $q->whereHas('provider', fn ($p) => $p->where('slug', $providerSlug)))
            ->get()->filter(fn (AiModel $m) => $m->supports($capability))->values();
    }

    public function syncFromConfig(): void
    {
        foreach (config('motionforge.providers') as $slug => $p) {
            $provider = AiProvider::updateOrCreate(['slug' => $slug], ['name' => $p['name'], 'base_url' => $p['base_url'], 'docs_url' => $p['docs_url'] ?? null, 'is_active' => true]);
            foreach ($p['models'] as $id => $m) {
                $model = AiModel::firstOrNew(['ai_provider_id' => $provider->id, 'model_id' => $id]);
                $model->fill([
                    'display_name' => $m['name'], 'capabilities' => $m['caps'], 'is_registry' => true,
                    'limits' => $m['limits'] ?? null,
                ]);
                // never overwrite prices an admin edited
                if (! $model->exists || $model->pricing_source === null || str_contains((string) $model->pricing_source, '(20')) {
                    $model->fill([
                        'input_price_per_mtok' => $m['in'] ?? null, 'output_price_per_mtok' => $m['out'] ?? null,
                        'video_price_per_second' => $m['video_per_second'] ?? null, 'pricing_source' => $m['source'] ?? null,
                        'pricing_verified_at' => isset($m['source']) ? '2026-11-18' : null,
                    ]);
                }
                $model->save();
            }
        }
    }

    /** Store models returned by a provider's list endpoint that are not in the registry (capabilities unverified). */
    public function recordDiscovered(AiProvider $provider, array $models): int
    {
        $new = 0;
        foreach ($models as $m) {
            $id = (string) ($m['id'] ?? '');
            if ($id === '' || strlen($id) > 120) {
                continue;
            }
            $model = AiModel::firstOrNew(['ai_provider_id' => $provider->id, 'model_id' => $id]);
            if (! $model->exists) {
                $model->fill(['display_name' => $m['name'] ?? $id, 'capabilities' => [], 'is_registry' => false, 'discovered_at' => now()])->save();
                $new++;
            }
        }

        return $new;
    }
}

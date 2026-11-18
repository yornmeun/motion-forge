<?php

namespace App\Http\Controllers;

use App\Enums\AICapability;
use App\Models\AiModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Capability registry + pricing table (admins edit; everyone can read). */
class ModelPreferenceController extends Controller
{
    public function index(): View
    {
        return view('settings.models', [
            'models' => AiModel::with('provider')->orderByDesc('is_registry')->orderBy('ai_provider_id')->orderBy('model_id')->get(),
            'capabilities' => AICapability::cases(),
        ]);
    }

    public function update(Request $request, AiModel $model): RedirectResponse
    {
        Gate::authorize('admin');
        $data = $request->validate([
            'capabilities' => ['array'], 'capabilities.*' => [Rule::in(array_column(AICapability::cases(), 'value'))],
            'input_price_per_mtok' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'output_price_per_mtok' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'video_price_per_second' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'pricing_source' => ['nullable', 'string', 'max:200'],
            'is_active' => ['boolean'],
        ]);
        $model->update(array_merge($data, ['capabilities' => $data['capabilities'] ?? [], 'is_registry' => true, 'pricing_verified_at' => now()->toDateString(), 'pricing_source' => ($data['pricing_source'] ?? null) ?: 'Edited by '.$request->user()->name]));

        return back()->with('status', "{$model->model_id} updated.");
    }
}

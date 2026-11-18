<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\AiRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class UsageController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $base = AiRequest::query()->where('user_id', $user->id)
            ->when($request->string('provider')->toString(), fn ($q, $p) => $q->whereHas('provider', fn ($x) => $x->where('slug', $p)))
            ->when($request->string('model')->toString(), fn ($q, $m) => $q->where('model', $m))
            ->when($request->string('project')->toString(), fn ($q, $p) => $q->where('project_id', $p))
            ->when($request->string('type')->toString(), fn ($q, $t) => $q->where('request_type', $t))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->date('from'), fn ($q, $d) => $q->where('created_at', '>=', $d->startOfDay()))
            ->when($request->date('to'), fn ($q, $d) => $q->where('created_at', '<=', $d->endOfDay()));
        $totals = (clone $base)->selectRaw('count(*) n, sum(input_tokens) tin, sum(output_tokens) tout, sum(media_units) media, sum(estimated_cost_usd) est, sum(confirmed_cost_usd) conf, sum(case when estimated_cost_usd is null and status = ? then 1 else 0 end) unpriced', ['succeeded'])->first();
        $daily = (clone $base)->where('created_at', '>=', now()->subDays(29)->startOfDay())->get(['created_at', 'estimated_cost_usd'])
            ->groupBy(fn ($r) => $r->created_at->format('Y-m-d'))->map(fn ($g) => round((float) $g->sum('estimated_cost_usd'), 4));
        $days = collect(range(29, 0))->mapWithKeys(fn ($i) => [now()->subDays($i)->format('Y-m-d') => $daily[now()->subDays($i)->format('Y-m-d')] ?? 0]);

        return view('usage.index', [
            'requests' => (clone $base)->with('provider', 'project')->latest()->paginate(25)->withQueryString(),
            'totals' => $totals, 'days' => $days,
            'byModel' => (clone $base)->selectRaw('model, count(*) n, sum(input_tokens) tin, sum(output_tokens) tout, sum(estimated_cost_usd) est, sum(confirmed_cost_usd) conf')->groupBy('model')->orderByDesc('est')->get(),
            'month' => $user->monthSpendUsd(), 'user' => $user,
            'providers' => AiProvider::orderBy('name')->get(), 'projects' => $user->projects()->orderBy('title')->get(['id', 'title']),
            'types' => AiRequest::where('user_id', $user->id)->distinct()->pluck('request_type'),
            'models' => AiRequest::where('user_id', $user->id)->distinct()->pluck('model'),
        ]);
    }

    public function budget(Request $request): RedirectResponse
    {
        $d = $request->validate(['monthly_budget_usd' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'budget_warning_pct' => ['required', 'integer', 'min:10', 'max:100']]);
        $request->user()->update($d);

        return back()->with('status', 'Budget settings saved.');
    }

    /** Record the provider-billed amount for a request (kept separate from the estimate). */
    public function confirm(Request $request, AiRequest $aiRequest): RedirectResponse
    {
        abort_unless($aiRequest->user_id === $request->user()->id || Gate::allows('admin'), 403);
        $d = $request->validate(['confirmed_cost_usd' => ['required', 'numeric', 'min:0', 'max:100000']]);
        $aiRequest->update($d);
        \App\Models\AiUsageRecord::updateOrCreate(['ai_request_id' => $aiRequest->id], ['user_id' => $aiRequest->user_id, 'project_id' => $aiRequest->project_id, 'provider_slug' => $aiRequest->provider->slug, 'model' => $aiRequest->model, 'period' => $aiRequest->created_at->format('Y-m'), 'estimated_cost_usd' => $aiRequest->estimated_cost_usd ?? 0, 'confirmed_cost_usd' => $d['confirmed_cost_usd'], 'source' => 'manual']);

        return back()->with('status', 'Confirmed cost recorded.');
    }
}

<x-layouts.app title="Usage & Costs">
    <x-slot:header><h1 class="text-lg">Usage & Costs</h1></x-slot:header>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat label="Requests (filtered)" :value="number_format((int) $totals->n)" icon="activity" />
        <x-ui.stat label="Tokens in / out" :value="number_format((int) $totals->tin).' / '.number_format((int) $totals->tout)" icon="binary" />
        <x-ui.stat label="Estimated cost" :value="'$'.number_format((float) $totals->est, 4)" :hint="(int) $totals->unpriced ? $totals->unpriced.' succeeded request(s) without a verified price' : 'From the editable pricing table'" icon="calculator" tone="warning" />
        <x-ui.stat label="Confirmed (billed)" :value="$totals->conf !== null ? '$'.number_format((float) $totals->conf, 4) : 'Not recorded'" hint="Enter billed amounts per request" icon="receipt" tone="success" />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <section class="card card-pad lg:col-span-2">
            <h2 class="mb-3">Estimated spend — last 30 days</h2>
            @php $max = max(0.0001, $days->max()); @endphp
            <div class="flex h-40 items-end gap-1" role="img" aria-label="Daily estimated spend chart">
                @foreach ($days as $d => $v)<div class="group relative flex-1 rounded-t bg-primary/70 hover:bg-primary" style="height: {{ max(1, $v / $max * 100) }}%"><span class="pointer-events-none absolute -top-7 left-1/2 hidden -translate-x-1/2 whitespace-nowrap rounded bg-elevated px-1.5 py-0.5 text-[10px] group-hover:block">{{ \Illuminate\Support\Carbon::parse($d)->format('M j') }}: ${{ number_format($v, 4) }}</span></div>@endforeach
            </div>
            <div class="mt-1 flex justify-between text-[10px] text-muted"><span>{{ \Illuminate\Support\Carbon::parse($days->keys()->first())->format('M j') }}</span><span>Today</span></div>
        </section>
        <section class="card card-pad">
            <h2 class="mb-3">Monthly budget</h2>
            <p class="text-sm">This month: <strong>${{ number_format($month, 4) }}</strong>@if($user->monthly_budget_usd) of ${{ number_format((float) $user->monthly_budget_usd, 2) }}@endif</p>
            @if ($user->monthly_budget_usd)@php $p = min(100, $month / max(0.0001, (float) $user->monthly_budget_usd) * 100); @endphp<div class="mt-2 h-2 overflow-hidden rounded-full bg-elevated"><div class="h-full {{ $p >= 100 ? 'bg-error' : ($p >= $user->budget_warning_pct ? 'bg-warning' : 'bg-primary') }}" style="width: {{ $p }}%"></div></div>@endif
            <form method="POST" action="{{ route('usage.budget') }}" class="mt-4 grid gap-3">@csrf
                <div><label class="label">Monthly limit (USD)</label><input name="monthly_budget_usd" type="number" step="0.01" min="0" value="{{ $user->monthly_budget_usd }}" class="input" placeholder="No limit"></div>
                <div><label class="label">Warn at (% of budget)</label><input name="budget_warning_pct" type="number" min="10" max="100" value="{{ $user->budget_warning_pct }}" class="input"></div>
                <button class="btn-primary">Save</button></form>
            <p class="hint mt-3">Per-project budgets are set on each project; per-connection limits in API Credentials.</p>
        </section>
    </div>

    <section class="card mt-6 overflow-x-auto">
        <h2 class="p-4 pb-0">By model</h2>
        <table class="table"><thead><tr><th>Model</th><th>Requests</th><th>Tokens in</th><th>Tokens out</th><th>Estimated</th><th>Confirmed</th></tr></thead><tbody>
            @forelse ($byModel as $m)<tr><td class="font-mono text-xs">{{ $m->model }}</td><td>{{ $m->n }}</td><td class="tabular-nums">{{ number_format((int) $m->tin) }}</td><td class="tabular-nums">{{ number_format((int) $m->tout) }}</td><td><x-ui.money :value="$m->est" /></td><td><x-ui.money :value="$m->conf" /></td></tr>
            @empty<tr><td colspan="6" class="text-muted">No usage yet.</td></tr>@endforelse
        </tbody></table>
    </section>

    <form method="GET" class="mt-6 flex flex-wrap gap-2">
        <select name="provider" class="input w-auto"><option value="">All providers</option>@foreach ($providers as $p)<option value="{{ $p->slug }}" @selected(request('provider') === $p->slug)>{{ $p->name }}</option>@endforeach</select>
        <select name="model" class="input w-auto"><option value="">All models</option>@foreach ($models as $m)<option @selected(request('model') === $m)>{{ $m }}</option>@endforeach</select>
        <select name="project" class="input w-auto"><option value="">All projects</option>@foreach ($projects as $p)<option value="{{ $p->id }}" @selected(request('project') === $p->id)>{{ $p->title }}</option>@endforeach</select>
        <select name="type" class="input w-auto"><option value="">All request types</option>@foreach ($types as $t)<option @selected(request('type') === $t)>{{ $t }}</option>@endforeach</select>
        <select name="status" class="input w-auto"><option value="">Any status</option>@foreach (['succeeded', 'failed', 'refused', 'blocked_budget', 'pending'] as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select>
        <input type="date" name="from" value="{{ request('from') }}" class="input w-auto" aria-label="From"><input type="date" name="to" value="{{ request('to') }}" class="input w-auto" aria-label="To">
        <button class="btn-secondary">Filter</button>
    </form>
    <section class="card mt-3 overflow-x-auto">
        <table class="table"><thead><tr><th>When</th><th>Provider · model</th><th>Type</th><th>Project</th><th>Tokens</th><th>Media</th><th>Duration</th><th>Estimated</th><th>Confirmed</th><th>Status</th></tr></thead><tbody>
            @forelse ($requests as $r)
                <tr><td class="whitespace-nowrap text-xs text-muted">{{ $r->created_at->format('M j H:i') }}</td><td><div class="text-xs">{{ $r->provider->name }}</div><div class="font-mono text-[11px] text-muted">{{ $r->model }}</div></td><td class="text-xs">{{ str_replace('_', ' ', $r->request_type) }}</td><td class="max-w-[10rem] truncate text-xs">{{ $r->project?->title ?? '—' }}</td>
                    <td class="tabular-nums text-xs">{{ number_format((int) $r->input_tokens) }} / {{ number_format((int) $r->output_tokens) }}</td><td class="text-xs">{{ $r->media_units ? $r->media_units.' s' : '—' }}</td><td class="text-xs">{{ $r->duration_ms ? number_format($r->duration_ms / 1000, 1).' s' : '—' }}</td>
                    <td><x-ui.money :value="$r->estimated_cost_usd" /></td>
                    <td><form method="POST" action="{{ route('usage.confirm', $r) }}" class="flex gap-1">@csrf<input name="confirmed_cost_usd" type="number" step="0.000001" min="0" value="{{ $r->confirmed_cost_usd }}" class="input w-24 py-1 text-xs" aria-label="Confirmed cost"><button class="btn-ghost btn-sm">Save</button></form></td>
                    <td><x-ui.status :status="$r->status" />@if($r->error_message)<div class="max-w-[12rem] truncate text-[11px] text-red-300" title="{{ $r->error_message }}">{{ $r->error_message }}</div>@endif</td></tr>
            @empty<tr><td colspan="10" class="text-muted">No requests match.</td></tr>@endforelse
        </tbody></table>
        <div class="p-4">{{ $requests->links() }}</div>
    </section>
</x-layouts.app>

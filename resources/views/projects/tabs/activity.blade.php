<div class="grid gap-6 lg:grid-cols-2">
    <section class="card card-pad">
        <h2 class="mb-3">AI requests</h2>
        <table class="table"><thead><tr><th>When</th><th>Type</th><th>Model</th><th>Tokens</th><th>Cost</th><th>Status</th></tr></thead><tbody>
            @forelse ($aiRequests as $r)
                <tr><td class="text-xs text-muted">{{ $r->created_at->diffForHumans() }}</td><td>{{ str_replace('_', ' ', $r->request_type) }}</td><td class="font-mono text-xs">{{ $r->model }}</td><td class="tabular-nums text-xs">{{ number_format((int) $r->input_tokens) }} / {{ number_format((int) $r->output_tokens) }}</td><td><x-ui.money :value="$r->estimated_cost_usd" /></td><td><x-ui.status :status="$r->status" /></td></tr>
            @empty<tr><td colspan="6" class="text-muted">No AI requests for this project.</td></tr>@endforelse
        </tbody></table>
        <a href="{{ route('usage.index', ['project' => $project->id]) }}" class="mt-3 inline-block text-sm text-indigo-300 hover:underline">Full usage for this project</a>
    </section>
    <section class="card card-pad">
        <h2 class="mb-3">Activity log</h2>
        <ol class="grid gap-3">
            @forelse ($activity as $a)
                <li class="text-sm"><div>{{ $a->description }}</div><div class="text-xs text-muted">{{ $a->user?->name ?? 'System' }} · {{ $a->created_at->format('M j, H:i') }}</div></li>
            @empty<li class="text-sm text-muted">No activity.</li>@endforelse
        </ol>
        <h2 class="mb-3 mt-6">Jobs</h2>
        <ul class="grid gap-2 text-sm">
            @foreach ($jobs as $j)
                <li class="flex flex-wrap items-center gap-2"><x-ui.status :status="$j->status" /><span>{{ str_replace('_', ' ', $j->type) }}@if($j->scene) · scene {{ $j->scene->sequence }}@endif</span><span class="text-xs text-muted">{{ $j->stage }}</span>
                    @if (in_array($j->status->value, ['failed', 'cancelled'], true))<form method="POST" action="{{ route('jobs.retry', $j) }}" class="ml-auto">@csrf<button class="btn-ghost btn-sm">Retry</button></form>@endif
                    @if (in_array($j->status->value, ['queued', 'running'], true))<form method="POST" action="{{ route('jobs.cancel', $j) }}" class="ml-auto">@csrf<button class="btn-ghost btn-sm">Cancel</button></form>@endif
                </li>
            @endforeach
        </ul>
    </section>
</div>

<x-layouts.app title="Render Queue">
    <x-slot:header><h1 class="text-lg">Render Queue</h1></x-slot:header>
    <div @if ($renders->contains(fn ($r) => in_array($r->status->value, ['queued', 'running'])) || $jobs->contains(fn ($j) => in_array($j->status->value, ['queued', 'running']))) x-data x-init="setTimeout(() => location.reload(), 5000)" @endif class="grid gap-6">
        <section class="card overflow-x-auto">
            <table class="table"><thead><tr><th>Project</th><th>Status</th><th>Stage</th><th>Started</th><th>Duration</th><th></th></tr></thead><tbody>
                @forelse ($renders as $r)
                    <tr><td><a class="hover:underline" href="{{ route('projects.show', [$r->project, 'tab' => 'render']) }}">{{ $r->project->title }}</a></td><td><x-ui.status :status="$r->status" /></td><td class="text-muted">{{ $r->stage }}@if($r->error)<div class="text-xs text-red-300">{{ \Illuminate\Support\Str::limit($r->error, 160) }}</div>@endif</td>
                        <td class="text-xs text-muted">{{ $r->started_at?->diffForHumans() ?? 'waiting for a worker' }}</td><td class="tabular-nums text-xs">{{ $r->started_at ? gmdate('H:i:s', (int) $r->started_at->diffInSeconds($r->finished_at ?? now())) : '—' }}</td>
                        <td>@if (in_array($r->status->value, ['queued', 'running']))<form method="POST" action="{{ route('render.cancel', $r) }}">@csrf<button class="btn-danger btn-sm">Cancel</button></form>@endif</td></tr>
                @empty<tr><td colspan="6" class="text-muted">No renders yet.</td></tr>@endforelse
            </tbody></table>
            <div class="p-4">{{ $renders->links() }}</div>
        </section>
        <section class="card overflow-x-auto">
            <h2 class="p-4 pb-0">Generation jobs</h2>
            <table class="table"><thead><tr><th>Job</th><th>Project</th><th>Status</th><th>Stage / progress</th><th>Updated</th><th></th></tr></thead><tbody>
                @forelse ($jobs as $j)
                    <tr><td>{{ str_replace('_', ' ', $j->type) }}@if($j->scene) · #{{ $j->scene->sequence }}@endif</td><td class="text-muted">{{ $j->project?->title }}</td><td><x-ui.status :status="$j->status" /></td>
                        <td class="text-xs text-muted">{{ $j->stage }}@if($j->progress_total) · {{ number_format((int) $j->progress_current) }}/{{ number_format($j->progress_total) }}@endif @if($j->error)<div class="text-red-300">{{ \Illuminate\Support\Str::limit($j->error, 160) }}</div>@endif</td>
                        <td class="text-xs text-muted">{{ $j->updated_at->diffForHumans() }}</td>
                        <td class="whitespace-nowrap">@if (in_array($j->status->value, ['failed', 'cancelled', 'waiting_approval']))<form method="POST" action="{{ route('jobs.retry', $j) }}" class="inline">@csrf @if($j->status->value === 'waiting_approval')<input type="hidden" name="approve" value="1">@endif<button class="btn-ghost btn-sm">{{ $j->status->value === 'waiting_approval' ? 'Approve' : 'Retry' }}</button></form>@endif
                            @if (in_array($j->status->value, ['queued', 'running']))<form method="POST" action="{{ route('jobs.cancel', $j) }}" class="inline">@csrf<button class="btn-ghost btn-sm">Cancel</button></form>@endif</td></tr>
                @empty<tr><td colspan="6" class="text-muted">No jobs.</td></tr>@endforelse
            </tbody></table>
        </section>
        <p class="text-xs text-muted">Jobs run on the queue worker (<code>php artisan queue:work</code>). Percentages appear only where the work reports real counts (rendered frames); otherwise the current stage is shown.</p>
    </div>
</x-layouts.app>

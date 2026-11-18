<section class="card card-pad" @if ($active) wire:poll.3s @endif>
    @if (! $rj)
        <p class="text-sm text-muted">No render has been started.</p>
    @else
        <div class="flex flex-wrap items-center gap-3">
            <x-ui.status :status="$rj->status" />
            <div class="flex-1 text-sm">{{ $rj->stage }}</div>
            @if ($active)<button wire:click="cancel('{{ $rj->id }}')" wire:confirm="Cancel this render?" class="btn-danger btn-sm">Cancel</button>@endif
        </div>
        @if ($progress && $progress['scenes_to_render'] > 0)
            @php $pct = $progress['frames_total'] ? round($progress['frames'] / $progress['frames_total'] * 100, 1) : null; @endphp
            <div class="mt-4">
                <div class="mb-1 flex justify-between text-xs text-muted"><span>Frames rendered</span><span class="tabular-nums">{{ number_format($progress['frames']) }}{{ $progress['frames_total'] ? ' / '.number_format($progress['frames_total']) : '' }} · {{ $progress['scenes_done'] }}/{{ $progress['scenes_to_render'] }} changed scenes @if($progress['scenes_failed']) · <span class="text-red-300">{{ $progress['scenes_failed'] }} failed</span>@endif</span></div>
                <div class="h-2 overflow-hidden rounded-full bg-elevated" role="progressbar" aria-valuenow="{{ $pct ?? 0 }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full bg-gradient-to-r from-primary to-accent transition-all" style="width: {{ $pct ?? 0 }}%"></div></div>
            </div>
        @elseif ($progress)
            <p class="mt-3 text-xs text-muted">All scene renders were reused from earlier renders.</p>
        @endif
        @if ($rj->error)<p class="mt-3 rounded-lg bg-error/10 p-3 text-sm text-red-200">{{ $rj->error }}</p>@endif
        <div class="mt-4 grid grid-cols-6 gap-1.5 sm:grid-cols-10">
            @foreach ($scenes as $s)
                @php $j = $sceneJobs[$s->id] ?? null; $st = $j?->status->value; @endphp
                <div title="Scene {{ $s->sequence }}{{ $j ? ': '.$j->stage : ' (reused)' }}{{ $s->error ? ' — '.$s->error : '' }}" class="rounded px-1 py-1 text-center font-mono text-[10px] {{ match($st) { 'succeeded' => 'bg-success/25 text-green-200', 'failed' => 'bg-error/30 text-red-200', 'running' => 'bg-accent/30 text-cyan-100 animate-pulse', 'queued' => 'bg-elevated text-muted', default => 'bg-success/10 text-green-300/70' } }}">
                    {{ $s->sequence }}@if ($st === 'running' && $j->progress_total)<br>{{ (int) round($j->progress_current / max(1, $j->progress_total) * 100) }}%@endif
                </div>
            @endforeach
        </div>
        @if ($sceneJobs->where('status', \App\Enums\JobStatus::Failed)->isNotEmpty())
            <div class="mt-3 flex flex-wrap gap-2">@foreach ($sceneJobs->where('status', \App\Enums\JobStatus::Failed) as $j)<form method="POST" action="{{ route('jobs.retry', $j) }}">@csrf<button class="btn-secondary btn-sm">Retry scene {{ $scenes->firstWhere('id', $j->scene_id)?->sequence }}</button></form>@endforeach</div>
        @endif
        @if ($rj->log)<details class="mt-4"><summary class="cursor-pointer text-xs text-muted">Pipeline log</summary><pre class="mt-2 max-h-64 overflow-auto rounded-lg bg-bg p-3 text-[11px] text-muted">{{ $rj->log }}</pre></details>@endif
    @endif
</section>

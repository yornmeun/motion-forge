<div class="grid gap-6 lg:grid-cols-3">
    <div class="grid gap-6 lg:col-span-2">
        <section class="card card-pad">
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex-1"><h2>Render</h2><p class="mt-1 text-sm text-muted">Scenes render in the sandboxed engine, then sound design, FFmpeg assembly, thumbnail, caption and QC. Unchanged scenes are reused.</p></div>
                @can('produce', $project)
                    <form method="POST" action="{{ route('render.start', $project) }}">@csrf<button class="btn-primary" @disabled($blockers)><i data-lucide="play" class="size-4"></i>{{ $final ? 'Re-render' : 'Render video' }}</button></form>
                @endcan
            </div>
            @if ($blockers)
                <ul class="mt-4 grid gap-1 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm text-amber-100">@foreach (array_slice($blockers, 0, 8) as $b)<li class="flex gap-2"><i data-lucide="circle-alert" class="mt-0.5 size-4 shrink-0"></i>{{ $b }}</li>@endforeach</ul>
            @endif
        </section>
        <livewire:render-monitor :project="$project" />
    </div>
    <aside class="card card-pad h-fit">
        <h2 class="mb-3">Recent renders</h2>
        <ul class="grid gap-3 text-sm">
            @forelse ($renderJobs as $rj)
                <li class="rounded-lg bg-bg/50 p-3"><div class="flex items-center justify-between"><x-ui.status :status="$rj->status" /><span class="text-xs text-muted">{{ $rj->created_at->diffForHumans() }}</span></div><div class="mt-1 text-muted">{{ $rj->stage }}</div>@if($rj->error)<div class="mt-1 text-xs text-red-300">{{ \Illuminate\Support\Str::limit($rj->error, 200) }}</div>@endif</li>
            @empty<li class="text-muted">No renders yet.</li>@endforelse
        </ul>
    </aside>
</div>

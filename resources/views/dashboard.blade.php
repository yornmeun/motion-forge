<x-layouts.app title="Dashboard">
    <x-slot:header><h1 class="text-lg">Dashboard</h1></x-slot:header>

    @unless ($hasConnection)
        <div class="card mb-6 flex flex-col gap-3 border-primary/40 bg-primary/10 p-5 sm:flex-row sm:items-center">
            <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-primary/20 text-indigo-200"><i data-lucide="plug" class="size-5"></i></span>
            <div class="flex-1"><div class="font-medium">Connect an AI provider</div><p class="text-sm text-muted">Add your API key to generate storyboards and animation with AI. The built-in template director works without a key.</p></div>
            <a href="{{ route('providers.index') }}" class="btn-primary">Connect provider</a>
        </div>
    @endunless

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat label="Total projects" :value="$stats['projects']" icon="folder-kanban" />
        <x-ui.stat label="In production" :value="$stats['in_production']" icon="clapperboard" tone="accent" />
        <x-ui.stat label="Completed videos" :value="$stats['completed']" icon="circle-check" tone="success" />
        <x-ui.stat label="Failed jobs (30 days)" :value="$stats['failed_jobs']" icon="triangle-alert" :tone="$stats['failed_jobs'] ? 'error' : 'muted'" />
    </div>
    <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <x-ui.stat label="AI usage this month" :value="number_format($stats['ai_requests']).' requests'" :hint="number_format($stats['tokens']).' tokens'" icon="sparkles" tone="primary" />
        <x-ui.stat label="Estimated API spend this month" :value="'$'.number_format($stats['estimated'], 4)" :hint="$stats['unpriced'] ? $stats['unpriced'].' request(s) on models without a verified price are not included' : 'Estimates from the pricing table; confirm against provider billing'" icon="wallet" tone="warning" />
    </div>

    @if ($running->isNotEmpty())
        <div class="card mt-6 card-pad">
            <h2 class="mb-3">Rendering now</h2>
            @foreach ($running as $rj)
                <a href="{{ route('projects.show', [$rj->project, 'tab' => 'render']) }}" class="flex items-center justify-between rounded-lg px-3 py-2 hover:bg-elevated"><span>{{ $rj->project->title }}</span><span class="text-sm text-muted">{{ $rj->stage }}</span></a>
            @endforeach
        </div>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <section class="lg:col-span-2">
            <div class="mb-3 flex items-center justify-between"><h2>Latest videos</h2><a href="{{ route('videos.index') }}" class="text-sm text-indigo-300 hover:underline">All videos</a></div>
            @if ($videos->isEmpty())
                <x-ui.empty icon="film" title="No finished videos yet" text="Create a project, approve its production package and render it." >
                    <a href="{{ route('projects.create') }}" class="btn-primary btn-sm">Create video</a>
                </x-ui.empty>
            @else
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    @foreach ($videos as $v)
                        @php $thumb = $v->project->thumbnails()->first(); @endphp
                        <a href="{{ route('projects.show', [$v->project, 'tab' => 'delivery']) }}" class="group">
                            <div class="aspect-[9/16] overflow-hidden rounded-lg border border-line bg-elevated">
                                @if ($thumb)<img src="{{ route('media.show', [$v->project, 'thumbnail', $thumb->id]) }}" alt="" class="size-full object-cover transition group-hover:scale-105">@endif
                            </div>
                            <div class="mt-2 truncate text-sm">{{ $v->project->title }}</div>
                            <div class="text-xs text-muted">{{ $v->created_at->diffForHumans() }}</div>
                        </a>
                    @endforeach
                </div>
            @endif

            <h2 class="mb-3 mt-8">Recent projects</h2>
            <div class="card divide-y divide-line/50">
                @forelse ($projects as $p)
                    <a href="{{ route('projects.show', $p) }}" class="flex items-center gap-4 px-4 py-3 hover:bg-elevated/50">
                        <div class="min-w-0 flex-1"><div class="truncate font-medium">{{ $p->title }}</div><div class="text-xs text-muted">{{ $p->production_mode->label() }} · updated {{ $p->updated_at->diffForHumans() }}</div></div>
                        <x-ui.status :status="$p->status" />
                    </a>
                @empty
                    <div class="p-4 text-sm text-muted">No projects yet.</div>
                @endforelse
            </div>
        </section>
        <section>
            <h2 class="mb-3">Recent activity</h2>
            <div class="card card-pad">
                <ol class="grid gap-4">
                    @forelse ($activity as $a)
                        <li class="flex gap-3 text-sm"><span class="mt-1.5 size-2 shrink-0 rounded-full {{ str_contains($a->action, 'fail') ? 'bg-error' : 'bg-primary' }}"></span>
                            <div class="min-w-0"><div>{{ $a->description }}</div><div class="text-xs text-muted">{{ $a->project?->title }} · {{ $a->created_at->diffForHumans() }}</div></div></li>
                    @empty
                        <li class="text-sm text-muted">Nothing yet.</li>
                    @endforelse
                </ol>
            </div>
        </section>
    </div>
</x-layouts.app>

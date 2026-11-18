<x-layouts.app title="My Projects">
    <x-slot:header><h1 class="text-lg">My Projects</h1></x-slot:header>
    <form method="GET" class="mb-5 flex flex-wrap gap-2">
        <div class="relative min-w-60 flex-1"><i data-lucide="search" class="absolute left-3 top-2.5 size-4 text-muted"></i><input data-search name="q" value="{{ request('q') }}" placeholder="Search projects  ( / )" class="input pl-9"></div>
        <select name="status" class="input w-auto" onchange="this.form.submit()"><option value="">All statuses</option>@foreach ($statuses as $s)<option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ $s->label() }}</option>@endforeach</select>
        <select name="mode" class="input w-auto" onchange="this.form.submit()"><option value="">All modes</option>@foreach ($modes as $m)<option value="{{ $m->value }}" @selected(request('mode') === $m->value)>{{ $m->label() }}</option>@endforeach</select>
        <button class="btn-secondary">Filter</button>
    </form>
    @if ($projects->isEmpty())
        <x-ui.empty icon="folder-plus" title="No projects found" text="Create a project to start a production.">
            <a href="{{ route('projects.create') }}" class="btn-primary btn-sm">Create video</a>
        </x-ui.empty>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($projects as $p)
                <a href="{{ route('projects.show', $p) }}" class="card card-pad group transition hover:border-primary/60">
                    <div class="flex items-start justify-between gap-2"><h2 class="line-clamp-2 group-hover:text-indigo-200">{{ $p->title }}</h2><x-ui.status :status="$p->status" /></div>
                    <p class="mt-2 line-clamp-2 min-h-10 text-sm text-muted">{{ $p->description ?: 'No description' }}</p>
                    <div class="mt-4 flex flex-wrap gap-2 text-xs text-muted">
                        <span class="badge tone-muted">{{ $p->production_mode->label() }}</span>
                        <span class="badge tone-muted">{{ $p->aspect_ratio }} · {{ $p->width }}×{{ $p->height }}</span>
                        <span class="badge tone-muted">{{ $p->generation_strategy === 'ai' ? 'AI direction' : 'Template director' }}</span>
                    </div>
                    <div class="mt-3 text-xs text-muted">Updated {{ $p->updated_at->diffForHumans() }}</div>
                </a>
            @endforeach
        </div>
        <div class="mt-6">{{ $projects->links() }}</div>
    @endif
</x-layouts.app>

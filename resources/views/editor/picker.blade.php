<x-layouts.app title="Scene Editor">
    <x-slot:header><h1 class="text-lg">Scene Editor</h1></x-slot:header>
    @if ($projects->isEmpty())
        <x-ui.empty icon="film" title="No projects with scenes yet" text="Approve a production package and generate a storyboard to edit scenes."><a href="{{ route('projects.index') }}" class="btn-primary btn-sm">Open projects</a></x-ui.empty>
    @else
        <div class="card divide-y divide-line/50">
            @foreach ($projects as $p)
                <a href="{{ route('editor.show', $p) }}" class="flex items-center gap-4 px-4 py-3 hover:bg-elevated/50"><i data-lucide="film" class="size-4 text-muted"></i><span class="flex-1">{{ $p->title }}</span><span class="text-xs text-muted">{{ $p->scenes()->count() }} scenes</span><x-ui.status :status="$p->status" /></a>
            @endforeach
        </div>
    @endif
</x-layouts.app>

<x-layouts.app :title="$project->title">
    <x-slot:header>
        <div class="flex min-w-0 items-center gap-3">
            <a href="{{ route('projects.index') }}" class="btn-ghost btn-sm" aria-label="Back to projects"><i data-lucide="arrow-left" class="size-4"></i></a>
            <h1 class="truncate text-lg">{{ $project->title }}</h1>
            <x-ui.status :status="$project->status" />
        </div>
    </x-slot:header>

    @php
        $tabs = ['overview' => 'Overview', 'package' => $project->workflow === 'preproduction' && ! $approved ? 'Script & package' : 'Package', 'scenes' => 'Scenes', 'render' => 'Render', 'delivery' => 'Delivery', 'activity' => 'Activity & usage'];
    @endphp
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-line/60">
        <nav class="flex gap-6 overflow-x-auto" aria-label="Project sections">
            @foreach ($tabs as $key => $label)
                <a href="{{ route('projects.show', [$project, 'tab' => $key]) }}" class="tab {{ $tab === $key ? 'active' : '' }}">{{ $label }}</a>
            @endforeach
        </nav>
        <div class="flex gap-2 pb-2">
            @if ($scenes->isNotEmpty())<a href="{{ route('editor.show', $project) }}" class="btn-secondary btn-sm"><i data-lucide="film" class="size-4"></i>Scene editor</a>@endif
        </div>
    </div>

    @include('projects.tabs.'.(array_key_exists($tab, $tabs) ? $tab : 'overview'))
</x-layouts.app>

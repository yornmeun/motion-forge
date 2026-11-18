<x-layouts.app title="Rendering">
    <x-slot:header><div class="text-sm text-muted">Settings › <span class="text-text">Rendering</span></div></x-slot:header>
    <h1>Rendering toolchain</h1>
    <p class="mt-1 text-sm text-muted">Each check runs the real command on this server.</p>
    <div class="card mt-6 max-w-3xl divide-y divide-line/50">
        @foreach ($checks as [$label, $result])
            <div class="flex items-center gap-3 px-4 py-3"><x-ui.status :status="$result ? 'pass' : 'fail'" /><span class="w-56">{{ $label }}</span><span class="truncate font-mono text-xs text-muted">{{ $result ?? 'not available' }}</span></div>
        @endforeach
        <div class="flex items-center gap-3 px-4 py-3"><x-ui.badge :tone="$originalConfigured && ! str_contains($engine['kind'], 'compatible') ? 'success' : 'accent'">Engine</x-ui.badge><div><div>{{ $engine['kind'] }}</div><div class="font-mono text-xs text-muted">{{ $engine['dir'] }} · {{ $engine['hash'] }}</div>@if($originalConfigured && str_contains($engine['kind'], 'compatible'))<div class="text-xs text-amber-300">KIKITOONS_ENGINE_PATH is set but core.js, bg.js and engine.js were not all found there.</div>@endif</div></div>
        <div class="flex items-center gap-3 px-4 py-3"><x-ui.badge>Queue</x-ui.badge><span>{{ $queue }} · run <code class="font-mono text-xs">php artisan queue:work</code> ({{ $workers }} workers recommended for parallel scene renders)</span></div>
    </div>
</x-layouts.app>

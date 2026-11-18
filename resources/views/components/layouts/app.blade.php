@props(['title' => null, 'wide' => false])
<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}MotionForge</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full" x-data="{ nav: false, shortcuts: false }" @shortcuts.window="shortcuts = true">
<div class="flex min-h-full">
    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-40 w-64 -translate-x-full border-r border-line/60 bg-sidebar transition lg:translate-x-0" :class="nav && 'translate-x-0'">
        <div class="flex h-16 items-center gap-3 border-b border-line/60 px-5">
            <span class="grid size-9 place-items-center rounded-lg bg-gradient-to-br from-primary to-secondary text-white"><i data-lucide="clapperboard" class="size-5"></i></span>
            <div class="leading-tight"><div class="font-semibold">MotionForge</div><div class="text-[11px] text-muted">Create. Animate. Render.</div></div>
        </div>
        @php
            $items = [
                ['dashboard', 'Dashboard', 'layout-dashboard', 'dashboard'],
                ['projects.index', 'My Projects', 'folder-kanban', 'projects.index'],
                ['projects.create', 'Create Video', 'circle-plus', 'projects.create'],
                ['studio.index', 'AI Studio', 'sparkles', 'studio.*'],
                ['editor.picker', 'Scene Editor', 'film', 'editor.*'],
                ['characters.index', 'Character Library', 'users', 'characters.*'],
                ['assets.index', 'Asset Library', 'images', 'assets.*'],
                ['render.queue', 'Render Queue', 'cpu', 'render.queue'],
                ['videos.index', 'Completed Videos', 'circle-play', 'videos.*'],
                ['usage.index', 'Usage & Costs', 'chart-column', 'usage.*'],
            ];
            $settings = [
                ['settings.profile', 'Profile'], ['providers.index', 'AI Providers'], ['credentials.index', 'API Credentials'],
                ['models.index', 'Model Preferences'], ['settings.rendering', 'Rendering'], ['settings.security', 'Security'],
            ];
        @endphp
        <nav class="flex h-[calc(100%-4rem)] flex-col gap-0.5 overflow-y-auto p-3" aria-label="Main">
            @foreach ($items as [$route, $label, $icon, $pattern])
                <a href="{{ route($route) }}" class="nav-link {{ request()->routeIs($pattern) && !($route === 'projects.index' && request()->routeIs('projects.create')) ? 'active' : '' }}"><i data-lucide="{{ $icon }}" class="size-4"></i>{{ $label }}</a>
            @endforeach
            <div x-data="{ open: {{ request()->routeIs('settings.*', 'providers.*', 'credentials.*', 'models.*') ? 'true' : 'false' }} }" class="mt-1">
                <button @click="open = !open" class="nav-link w-full" :aria-expanded="open"><i data-lucide="settings" class="size-4"></i>Settings<i data-lucide="chevron-down" class="ml-auto size-4 transition" :class="open && 'rotate-180'"></i></button>
                <div x-show="open" x-cloak class="ml-7 mt-0.5 grid gap-0.5 border-l border-line/60 pl-2">
                    @foreach ($settings as [$route, $label])
                        <a href="{{ route($route) }}" class="nav-link py-1.5 {{ request()->routeIs($route) ? 'active' : '' }}">{{ $label }}</a>
                    @endforeach
                </div>
            </div>
            <div class="mt-auto border-t border-line/60 pt-3">
                <div class="flex items-center gap-3 px-3 py-2">
                    <span class="grid size-8 place-items-center rounded-full bg-elevated text-xs font-semibold">{{ strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
                    <div class="min-w-0 flex-1 text-sm"><div class="truncate">{{ auth()->user()->name }}</div><div class="truncate text-xs text-muted">{{ auth()->user()->role?->name ?? 'No role' }}</div></div>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn-ghost btn-sm" title="Sign out" aria-label="Sign out"><i data-lucide="log-out" class="size-4"></i></button></form>
                </div>
            </div>
        </nav>
    </aside>
    <div x-show="nav" x-cloak @click="nav = false" class="fixed inset-0 z-30 bg-black/50 lg:hidden"></div>

    <div class="flex min-w-0 flex-1 flex-col lg:pl-64">
        <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-line/60 bg-bg/85 px-4 backdrop-blur sm:px-6">
            <button class="btn-ghost lg:hidden" @click="nav = true" aria-label="Open navigation"><i data-lucide="menu" class="size-5"></i></button>
            <div class="min-w-0 flex-1">{{ $header ?? '' }}</div>
            <button class="btn-ghost btn-sm hidden sm:inline-flex" @click="shortcuts = true" title="Keyboard shortcuts"><span class="kbd">?</span></button>
            <a href="{{ route('projects.create') }}" class="btn-primary btn-sm"><i data-lucide="plus" class="size-4"></i><span class="hidden sm:inline">New video</span></a>
        </header>
        <main class="flex-1 {{ $wide ? 'p-0' : 'mx-auto w-full max-w-7xl p-4 sm:p-6' }}">
            @if (session('status'))
                <div class="mb-4 flex items-start gap-2 rounded-lg border border-success/30 bg-success/10 px-4 py-3 text-sm text-green-200" role="status"><i data-lucide="circle-check" class="mt-0.5 size-4 shrink-0"></i>{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="mb-4 rounded-lg border border-error/30 bg-error/10 px-4 py-3 text-sm text-red-200" role="alert">
                    <div class="flex items-start gap-2"><i data-lucide="circle-alert" class="mt-0.5 size-4 shrink-0"></i><div>@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div></div>
                </div>
            @endif
            {{ $slot }}
        </main>
    </div>
</div>

{{-- Toasts --}}
<div x-data="{ items: [] }" @toast.window="const id = Date.now(); items.push({ id, ...$event.detail }); setTimeout(() => items = items.filter(i => i.id !== id), 5000)" class="fixed bottom-4 right-4 z-50 grid w-80 gap-2" aria-live="polite">
    <template x-for="t in items" :key="t.id">
        <div class="card flex items-start gap-2 px-4 py-3 text-sm shadow-xl" :class="{ 'border-error/50': t.tone === 'error', 'border-success/50': t.tone === 'success' }">
            <span class="mt-1 size-2 shrink-0 rounded-full" :class="{ 'bg-error': t.tone === 'error', 'bg-success': t.tone === 'success', 'bg-accent': !['error','success'].includes(t.tone) }"></span><span x-text="t.message"></span>
        </div>
    </template>
</div>

{{-- Shortcuts --}}
<div x-show="shortcuts" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/60 p-4" @keydown.escape.window="shortcuts = false" @click.self="shortcuts = false">
    <div class="card card-pad w-full max-w-sm">
        <h2 class="mb-3">Keyboard shortcuts</h2>
        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt><span class="kbd">g</span> <span class="kbd">d</span></dt><dd class="text-muted">Dashboard</dd>
            <dt><span class="kbd">g</span> <span class="kbd">p</span></dt><dd class="text-muted">Projects</dd>
            <dt><span class="kbd">g</span> <span class="kbd">r</span></dt><dd class="text-muted">Render queue</dd>
            <dt><span class="kbd">g</span> <span class="kbd">u</span></dt><dd class="text-muted">Usage & costs</dd>
            <dt><span class="kbd">n</span></dt><dd class="text-muted">New video</dd>
            <dt><span class="kbd">/</span></dt><dd class="text-muted">Search</dd>
            <dt><span class="kbd">Ctrl</span> <span class="kbd">Z</span> / <span class="kbd">Y</span></dt><dd class="text-muted">Undo / redo (scene editor)</dd>
            <dt><span class="kbd">Space</span></dt><dd class="text-muted">Play / pause preview (scene editor)</dd>
        </dl>
    </div>
</div>
@livewireScripts
</body>
</html>

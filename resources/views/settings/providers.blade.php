<x-layouts.app title="AI Providers">
    <x-slot:header><div class="text-sm text-muted">Settings › <span class="text-text">AI Providers</span></div></x-slot:header>
    <div class="mb-6 flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
        <div><h1>AI Providers</h1><p class="mt-1 text-sm text-muted">Connect your own API keys. Keys are encrypted at rest, never shown again, and used only by the server.</p></div>
        <a href="{{ route('credentials.index') }}" class="btn-secondary btn-sm"><i data-lucide="key-round" class="size-4"></i>Manage all credentials</a>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($providers as $provider)
            @php $creds = $credentials->where('ai_provider_id', $provider->id); $pm = $models[$provider->slug] ?? collect(); @endphp
            <section class="card" x-data="{ adding: {{ $creds->isEmpty() ? 'false' : 'false' }} }">
                <div class="flex items-center gap-3 border-b border-line/60 p-5">
                    <span class="grid size-10 place-items-center rounded-lg bg-elevated"><i data-lucide="{{ ['claude' => 'sparkle', 'gemini' => 'gem', 'openai' => 'brain', 'kimi' => 'moon'][$provider->slug] ?? 'bot' }}" class="size-5"></i></span>
                    <div class="flex-1">
                        <h2>{{ $provider->name }}</h2>
                        <div class="mt-0.5 flex flex-wrap gap-1">
                            @foreach ($pm->where('is_registry', true)->flatMap(fn ($m) => $m->capabilities)->unique()->values() as $cap)
                                <x-ui.badge :tone="$cap === 'native_video' ? 'accent' : 'muted'">{{ \App\Enums\AICapability::from($cap)->label() }}</x-ui.badge>
                            @endforeach
                        </div>
                    </div>
                    @if ($creds->contains(fn ($c) => $c->isUsable()))<x-ui.badge tone="success">Connected</x-ui.badge>@else<x-ui.badge tone="muted">Not connected</x-ui.badge>@endif
                </div>
                <div class="grid gap-4 p-5">
                    @if ($provider->slug === 'openai')
                        <p class="rounded-lg bg-elevated px-3 py-2 text-xs text-muted">OpenAI’s Sora / Videos API was shut down on 2026-09-24, so OpenAI is available here for planning and code only.</p>
                    @elseif (! $pm->contains(fn ($m) => in_array('native_video', $m->capabilities ?? [])))
                        <p class="rounded-lg bg-elevated px-3 py-2 text-xs text-muted">No native video generation model is registered for {{ $provider->name }}; use it for storyboards and animation code.</p>
                    @endif
                    @foreach ($creds as $c)
                        <div class="rounded-lg border border-line/70 p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="font-medium">{{ $c->name }}</div>
                                <x-ui.badge :tone="$c->status->tone()">{{ $c->status->label() }}</x-ui.badge>
                                @unless ($c->is_enabled)<x-ui.badge tone="warning">Disabled</x-ui.badge>@endunless
                                <span class="ml-auto font-mono text-xs text-muted">{{ $c->maskedKey() }}</span>
                            </div>
                            @if ($c->status_message)<p class="mt-2 text-xs {{ $c->status->value === 'connected' ? 'text-muted' : 'text-red-300' }}">{{ $c->status_message }}</p>@endif
                            <p class="mt-1 text-xs text-muted">{{ $c->last_tested_at ? 'Tested '.$c->last_tested_at->diffForHumans() : 'Never tested' }}</p>
                            <form method="POST" action="{{ route('credentials.update', $c) }}" class="mt-3 flex flex-wrap items-end gap-2">
                                @csrf @method('PATCH')
                                <div class="min-w-48 flex-1">
                                    <label class="label" for="dm-{{ $c->id }}">Default model</label>
                                    <select id="dm-{{ $c->id }}" name="default_model" class="input" @cannot('manage', $c) disabled @endcannot>
                                        <option value="">Automatic by task</option>
                                        @foreach ($pm->where('is_registry', true) as $m)
                                            <option value="{{ $m->model_id }}" @selected($c->default_model === $m->model_id)>{{ $m->display_name ?? $m->model_id }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                @can('manage', $c)<button class="btn-secondary">Save</button>@endcan
                            </form>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('credentials.test', $c) }}" x-data="{ busy: false }" @submit="busy = true">@csrf<button class="btn-secondary btn-sm" :disabled="busy"><i data-lucide="plug-zap" class="size-4"></i><span x-text="busy ? 'Testing…' : 'Test connection'"></span></button></form>
                                <a href="{{ route('credentials.index') }}#c-{{ $c->id }}" class="btn-ghost btn-sm"><i data-lucide="sliders-horizontal" class="size-4"></i>Manage</a>
                            </div>
                        </div>
                    @endforeach

                    @can('manage-credentials')
                        <div x-show="!adding"><button class="btn-primary" @click="adding = true; $nextTick(() => $refs.key.focus())"><i data-lucide="plus" class="size-4"></i>Connect {{ $provider->name }}</button></div>
                        <form x-show="adding" x-cloak method="POST" action="{{ route('credentials.store') }}" class="grid gap-3 rounded-lg border border-primary/40 bg-primary/5 p-4" x-data="{ busy: false }" @submit="busy = true">
                            @csrf
                            <input type="hidden" name="provider" value="{{ $provider->slug }}">
                            <div><label class="label" for="name-{{ $provider->slug }}">Connection name</label><input id="name-{{ $provider->slug }}" name="name" class="input" value="{{ $provider->name }}" required maxlength="80"></div>
                            <div><label class="label" for="key-{{ $provider->slug }}">API key</label><input id="key-{{ $provider->slug }}" x-ref="key" name="api_key" type="password" class="input font-mono" required autocomplete="off" spellcheck="false" placeholder="Paste your {{ $provider->name }} API key"><p class="hint">The key is tested with a real authenticated request before it is marked connected.</p></div>
                            <div><label class="label" for="lim-{{ $provider->slug }}">Monthly usage limit (USD, optional)</label><input id="lim-{{ $provider->slug }}" name="monthly_limit_usd" type="number" step="0.01" min="0" class="input" placeholder="No limit"></div>
                            <div class="flex gap-2"><button class="btn-primary" :disabled="busy"><span x-text="busy ? 'Connecting…' : 'Connect and test'"></span></button><button type="button" class="btn-ghost" @click="adding = false">Cancel</button></div>
                        </form>
                    @endcan
                </div>
            </section>
        @endforeach
    </div>
</x-layouts.app>

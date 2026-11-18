<x-layouts.app title="API Credentials">
    <x-slot:header><div class="text-sm text-muted">Settings › <span class="text-text">API Credentials</span></div></x-slot:header>
    <h1>API Credentials</h1>
    <p class="mt-1 text-sm text-muted">Rotate, limit, disable or revoke connections. Server-managed connections come from environment variables and are administered by an admin.</p>
    <div class="mt-6 grid gap-4">
        @forelse ($credentials as $c)
            @php $u = $usage[$c->id] ?? null; @endphp
            <section id="c-{{ $c->id }}" class="card card-pad">
                <div class="flex flex-wrap items-center gap-2">
                    <h2>{{ $c->name }}</h2><span class="text-sm text-muted">{{ $c->provider->name }}</span>
                    <x-ui.badge :tone="$c->status->tone()">{{ $c->status->label() }}</x-ui.badge>
                    @if ($c->isServerManaged())<x-ui.badge tone="accent">Server-managed</x-ui.badge>@endif
                    <span class="ml-auto font-mono text-xs text-muted">{{ $c->maskedKey() }}</span>
                </div>
                <div class="mt-3 grid gap-3 text-sm sm:grid-cols-4">
                    <div><div class="label">This month</div>{{ $u?->n ?? 0 }} requests</div>
                    <div><div class="label">Estimated</div><x-ui.money :value="$u?->est" /></div>
                    <div><div class="label">Confirmed</div><x-ui.money :value="$u?->conf" /></div>
                    <div><div class="label">Monthly limit</div>{{ $c->monthly_limit_usd !== null ? '$'.number_format((float) $c->monthly_limit_usd, 2) : 'None' }}</div>
                </div>
                @can('manage', $c)
                    <form method="POST" action="{{ route('credentials.update', $c) }}" class="mt-4 grid gap-3 border-t border-line/60 pt-4 sm:grid-cols-[1fr_1fr_10rem_auto] sm:items-end">
                        @csrf @method('PATCH')
                        <div><label class="label">Name</label><input name="name" value="{{ $c->name }}" class="input" maxlength="80"></div>
                        @unless ($c->isServerManaged())
                            <div><label class="label">Rotate key</label><input name="api_key" type="password" class="input font-mono" autocomplete="off" placeholder="New API key (optional)"></div>
                        @else <div class="hint">Rotate this key in the server environment.</div> @endunless
                        <div><label class="label">Monthly limit $</label><input name="monthly_limit_usd" type="number" step="0.01" min="0" value="{{ $c->monthly_limit_usd }}" class="input"></div>
                        <div class="flex items-center gap-3">
                            <input type="hidden" name="is_enabled" value="0">
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_enabled" value="1" @checked($c->is_enabled) class="accent-primary">Enabled</label>
                            <button class="btn-primary">Save</button>
                        </div>
                    </form>
                    <div class="mt-3 flex gap-2">
                        <form method="POST" action="{{ route('credentials.test', $c) }}">@csrf<button class="btn-secondary btn-sm"><i data-lucide="plug-zap" class="size-4"></i>Test</button></form>
                        <x-ui.confirm :action="route('credentials.destroy', $c)" method="DELETE" label="Revoke" icon="trash-2" message="This deletes the stored key. Projects using this connection will need another connection. Usage history is kept." />
                    </div>
                @endcan
            </section>
        @empty
            <x-ui.empty icon="key-round" title="No credentials yet" text="Connect a provider to start using AI.">
                <a href="{{ route('providers.index') }}" class="btn-primary btn-sm">Connect a provider</a>
            </x-ui.empty>
        @endforelse
    </div>
</x-layouts.app>

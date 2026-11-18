<x-layouts.app title="Model Preferences">
    <x-slot:header><div class="text-sm text-muted">Settings › <span class="text-text">Model Preferences</span></div></x-slot:header>
    <h1>Model capability registry & pricing</h1>
    <p class="mt-1 max-w-3xl text-sm text-muted">MotionForge routes a task only to models registered for that capability. Models discovered from a provider’s model list start with no capabilities until an admin verifies them. Prices drive estimates only; “n/a” means no verified price.</p>
    <div class="card mt-6 overflow-x-auto">
        <table class="table">
            <thead><tr><th>Model</th><th>Capabilities</th><th>Input / 1M</th><th>Output / 1M</th><th>Video / s</th><th>Source</th>@can('admin')<th></th>@endcan</tr></thead>
            <tbody>
            @foreach ($models as $m)
                <tr x-data="{ edit: false }">
                    <td><div class="font-mono text-xs">{{ $m->model_id }}</div><div class="text-xs text-muted">{{ $m->provider->name }} @unless($m->is_registry)· discovered, unverified @endunless @unless($m->is_active)· inactive @endunless</div></td>
                    <td class="max-w-xs"><div class="flex flex-wrap gap-1">@forelse ($m->capabilities ?? [] as $cap)<x-ui.badge :tone="$cap === 'native_video' ? 'accent' : 'muted'">{{ \App\Enums\AICapability::from($cap)->label() }}</x-ui.badge>@empty<span class="text-xs text-muted">none</span>@endforelse</div></td>
                    <td><x-ui.money :value="$m->input_price_per_mtok" /></td>
                    <td><x-ui.money :value="$m->output_price_per_mtok" /></td>
                    <td><x-ui.money :value="$m->video_price_per_second" /></td>
                    <td class="max-w-[14rem] text-xs text-muted">{{ $m->pricing_source ?? '—' }}</td>
                    @can('admin')
                        <td><button class="btn-ghost btn-sm" @click="edit = !edit">Edit</button>
                            <div x-show="edit" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/60 p-4" @click.self="edit = false" @keydown.escape.window="edit = false">
                                <form method="POST" action="{{ route('models.update', $m) }}" class="card card-pad grid w-full max-w-lg gap-3">
                                    @csrf @method('PATCH')
                                    <h2>{{ $m->model_id }}</h2>
                                    <div><div class="label">Capabilities</div><div class="grid grid-cols-2 gap-1">@foreach ($capabilities as $cap)<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="capabilities[]" value="{{ $cap->value }}" @checked(in_array($cap->value, $m->capabilities ?? [])) class="accent-primary">{{ $cap->label() }}</label>@endforeach</div></div>
                                    <div class="grid grid-cols-3 gap-2">
                                        <div><label class="label">Input $/1M</label><input name="input_price_per_mtok" type="number" step="0.0001" min="0" value="{{ $m->input_price_per_mtok }}" class="input"></div>
                                        <div><label class="label">Output $/1M</label><input name="output_price_per_mtok" type="number" step="0.0001" min="0" value="{{ $m->output_price_per_mtok }}" class="input"></div>
                                        <div><label class="label">Video $/s</label><input name="video_price_per_second" type="number" step="0.0001" min="0" value="{{ $m->video_price_per_second }}" class="input"></div>
                                    </div>
                                    <div><label class="label">Pricing source</label><input name="pricing_source" value="{{ $m->pricing_source }}" class="input" placeholder="e.g. provider pricing page, date"></div>
                                    <input type="hidden" name="is_active" value="0"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($m->is_active) class="accent-primary">Active</label>
                                    <div class="flex justify-end gap-2"><button type="button" class="btn-ghost" @click="edit = false">Cancel</button><button class="btn-primary">Save</button></div>
                                </form>
                            </div>
                        </td>
                    @endcan
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</x-layouts.app>

<x-layouts.app title="AI Studio">
    <x-slot:header><h1 class="text-lg">AI Studio</h1></x-slot:header>
    <div class="grid gap-6 lg:grid-cols-[1fr_20rem]" x-data="{ creds: @js($credentials), cred: '', model: '', system: '', prompt: '', out: null, err: '', busy: false, approve: false,
        get models() { return (this.creds.find(c => c.id === this.cred) || {}).models || []; },
        async run() { this.busy = true; this.err = ''; this.out = null;
            try { this.out = await mfFetch('{{ route('studio.run') }}', { method: 'POST', body: { credential_id: this.cred, model: this.model || null, system: this.system || null, prompt: this.prompt, approved: this.approve } }); }
            catch (e) { this.err = e.message; } finally { this.busy = false; } } }">
        <section class="card card-pad grid gap-3">
            <p class="text-sm text-muted">Try a prompt against a connected text model. Calls go through the same routing, budget checks and usage logging as production.</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <select x-model="cred" class="input" aria-label="Connection"><option value="">Choose a connection</option><template x-for="c in creds" :key="c.id"><option :value="c.id" x-text="c.name + (c.usable ? '' : ' (not connected)')" :disabled="!c.usable"></option></template></select>
                <select x-model="model" class="input" aria-label="Model"><option value="">Connection default</option><template x-for="m in models"><option :value="m" x-text="m"></option></template></select>
            </div>
            <textarea x-model="system" rows="2" class="input" placeholder="System prompt (optional)"></textarea>
            <textarea x-model="prompt" rows="8" class="input" placeholder="Prompt"></textarea>
            <div class="flex items-center gap-3"><button class="btn-primary" @click="run()" :disabled="busy || !cred || !prompt.trim()"><i data-lucide="send" class="size-4"></i><span x-text="busy ? 'Running…' : 'Run'"></span></button>
                <label class="flex items-center gap-2 text-xs text-muted"><input type="checkbox" x-model="approve" class="accent-primary">Approve cost above threshold</label></div>
            <p class="text-sm text-red-300" x-show="err" x-text="err"></p>
            <template x-if="out"><div class="rounded-lg border border-line bg-bg/60 p-4">
                <div class="mb-2 flex flex-wrap gap-3 text-xs text-muted"><span x-text="out.route"></span><span x-text="(out.input_tokens ?? '?') + ' in / ' + (out.output_tokens ?? '?') + ' out tokens'"></span><span x-text="out.cost !== null ? '$' + Number(out.cost).toFixed(5) : 'cost n/a'"></span><span x-text="out.duration_ms + ' ms'"></span></div>
                <pre class="whitespace-pre-wrap text-sm" x-text="out.text"></pre></div></template>
        </section>
        <aside class="card card-pad h-fit"><h2 class="mb-3">Recent studio requests</h2><ul class="grid gap-2 text-sm">@forelse ($recent as $r)<li class="flex justify-between gap-2"><span class="truncate font-mono text-xs">{{ $r->model }}</span><x-ui.status :status="$r->status" /></li>@empty<li class="text-muted">None yet.</li>@endforelse</ul></aside>
    </div>
</x-layouts.app>

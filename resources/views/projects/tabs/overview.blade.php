<div class="grid gap-6 lg:grid-cols-3">
    <div class="grid gap-6 lg:col-span-2">
        {{-- pipeline --}}
        @php
            $steps = [
                ['Package approved', (bool) $approved, 'package'],
                ['Storyboard', $scenes->isNotEmpty(), 'scenes'],
                ['Scenes generated', $scenes->isNotEmpty() && $scenes->every(fn ($s) => $s->source === 'ai_video' ? $s->assets->where('type', 'ai_video')->where('status', 'approved')->isNotEmpty() : $s->currentVersion?->codeIsValid()), 'scenes'],
                ['Rendered', (bool) $final, 'render'],
                ['Delivered', $project->status->value === 'completed', 'delivery'],
            ];
        @endphp
        <section class="card card-pad">
            <h2 class="mb-4">Production pipeline</h2>
            <ol class="grid gap-3 sm:grid-cols-5">
                @foreach ($steps as $i => [$label, $done, $tabKey])
                    <li><a href="{{ route('projects.show', [$project, 'tab' => $tabKey]) }}" class="block rounded-lg border p-3 transition hover:border-muted {{ $done ? 'border-success/40 bg-success/10' : 'border-line' }}">
                        <div class="flex items-center gap-2 text-xs text-muted"><span class="grid size-5 place-items-center rounded-full {{ $done ? 'bg-success text-black' : 'bg-elevated' }}">@if($done)<i data-lucide="check" class="size-3"></i>@else{{ $i + 1 }}@endif</span>Step {{ $i + 1 }}</div>
                        <div class="mt-1 text-sm">{{ $label }}</div>
                    </a></li>
                @endforeach
            </ol>
        </section>

        <section class="card card-pad">
            <h2 class="mb-4">Project settings</h2>
            <form method="POST" action="{{ route('projects.update', $project) }}" class="grid gap-4 sm:grid-cols-2">
                @csrf @method('PUT')
                <div class="sm:col-span-2"><label class="label">Title</label><input name="title" value="{{ $project->title }}" class="input" required maxlength="160"></div>
                <div class="sm:col-span-2"><label class="label">Description</label><textarea name="description" rows="2" class="input">{{ $project->description }}</textarea></div>
                <div><label class="label">Genre</label><input name="genre" value="{{ $project->genre }}" class="input"></div>
                <div><label class="label">Subtitle position (y)</label><input name="settings[subtitles][y]" type="number" min="220" max="1450" value="{{ $project->setting('subtitles.y', 1344) }}" class="input"></div>
                <div><label class="label">Thumbnail title</label><input name="settings[thumbnail][title]" maxlength="40" value="{{ $project->setting('thumbnail.title') }}" class="input" placeholder="Generated if empty"></div>
                <div><label class="label">Atmosphere</label><select name="settings[atmosphere]" class="input">@foreach (\App\Services\Production\PlanVocabulary::LOOKS as $l)<option value="{{ $l }}" @selected($project->setting('atmosphere') === $l)>{{ ucfirst($l) }}</option>@endforeach</select></div>
                <div class="sm:col-span-2 flex justify-end"><button class="btn-primary">Save</button></div>
            </form>
        </section>
    </div>

    <div class="grid h-fit gap-6">
        <section class="card card-pad">
            <h2 class="mb-3">Output</h2>
            <dl class="grid gap-2 text-sm">
                <div class="flex justify-between"><dt class="text-muted">Mode</dt><dd>{{ $project->production_mode->label() }}</dd></div>
                <div class="flex justify-between"><dt class="text-muted">Format</dt><dd>{{ $project->width }}×{{ $project->height }} · {{ $project->aspect_ratio }} · {{ $project->frame_rate }} fps</dd></div>
                <div class="flex justify-between"><dt class="text-muted">Codecs</dt><dd>H.264 + AAC, −14 LUFS</dd></div>
                <div class="flex justify-between"><dt class="text-muted">Length</dt><dd>{{ $scenes->isNotEmpty() ? gmdate('i:s', (int) $scenes->last()->end_time) : ($approved?->audio_duration ? gmdate('i:s', (int) $approved->audio_duration).' voice' : '—') }}</dd></div>
            </dl>
        </section>

        <section class="card card-pad">
            <h2 class="mb-3">AI configuration</h2>
            <form method="POST" action="{{ route('projects.ai', $project) }}" class="grid gap-3" x-data="{ s: '{{ $project->generation_strategy }}', cred: '{{ $project->ai_credential_id }}', vcred: '{{ $project->video_credential_id }}', fb: {{ $project->fallback_enabled ? 'true' : 'false' }}, fcred: '{{ $project->fallback_credential_id }}', tm: @js($textModels), vm: @js($videoModels) }">
                @csrf @method('PATCH')
                <div><label class="label">Direction</label><select name="generation_strategy" x-model="s" class="input"><option value="ai">AI direction</option><option value="template">Template director (no AI)</option></select></div>
                <div x-show="s === 'ai'" class="grid gap-3">
                    <select name="ai_credential_id" x-model="cred" class="input" aria-label="Provider"><option value="">Automatic</option>@foreach ($credentials as $c)<option value="{{ $c['id'] }}">{{ $c['name'] }} — {{ $c['provider'] }}{{ $c['usable'] ? '' : ' (not connected)' }}</option>@endforeach</select>
                    <select name="ai_model" class="input" aria-label="Model"><option value="">Connection default</option><template x-for="m in (tm[cred] || [])" :key="m.id"><option :value="m.id" :selected="m.id === @js($project->ai_model)" x-text="m.name"></option></template></select>
                </div>
                @if ($project->production_mode->value !== 'kikitoons')
                    <select name="video_credential_id" x-model="vcred" class="input" aria-label="Video provider"><option value="">No video provider</option>@foreach ($credentials as $c)<option value="{{ $c['id'] }}" x-show="(vm['{{ $c['id'] }}'] || []).length">{{ $c['name'] }}</option>@endforeach</select>
                    <select name="video_model" class="input" aria-label="Video model"><option value="">Default</option><template x-for="m in (vm[vcred] || [])" :key="m.id"><option :value="m.id" :selected="m.id === @js($project->video_model)" x-text="m.name"></option></template></select>
                @endif
                <div><label class="label">Budget (USD)</label><input name="budget_limit_usd" type="number" step="0.01" min="0" value="{{ $project->budget_limit_usd }}" class="input" placeholder="No limit"></div>
                <input type="hidden" name="fallback_enabled" value="0">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="fallback_enabled" value="1" x-model="fb" class="accent-primary">Fallback provider</label>
                <div x-show="fb" class="grid gap-2"><select name="fallback_credential_id" x-model="fcred" class="input">@foreach ($credentials as $c)<option value="{{ $c['id'] }}">{{ $c['name'] }}</option>@endforeach</select>
                    <select name="fallback_model" class="input"><option value="">Default</option><template x-for="m in (tm[fcred] || [])" :key="m.id"><option :value="m.id" x-text="m.name"></option></template></select></div>
                <button class="btn-primary">Save AI settings</button>
            </form>
            <div class="mt-4 border-t border-line/60 pt-3 text-sm">
                <div class="flex justify-between"><span class="text-muted">Spent (estimated)</span><x-ui.money :value="$spent" /></div>
                @if ($budgetPct !== null)
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-elevated" role="progressbar" aria-valuenow="{{ $budgetPct }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full {{ $budgetPct >= 100 ? 'bg-error' : ($budgetPct >= auth()->user()->budget_warning_pct ? 'bg-warning' : 'bg-primary') }}" style="width: {{ min(100, $budgetPct) }}%"></div></div>
                    <div class="mt-1 text-xs text-muted">{{ $budgetPct }}% of ${{ number_format((float) $project->budget_limit_usd, 2) }}@if((float) $project->approved_overrun_usd > 0) + ${{ number_format((float) $project->approved_overrun_usd, 2) }} approved overrun @endif</div>
                    <form method="POST" action="{{ route('projects.overrun', $project) }}" class="mt-2 flex gap-2">@csrf<input name="amount_usd" type="number" step="0.01" min="0.01" class="input" placeholder="Approve extra $"><button class="btn-secondary btn-sm">Approve</button></form>
                @endif
            </div>
        </section>

        @can('delete', $project)
            <section class="card card-pad">
                <h2 class="mb-3">Team</h2>
                <ul class="mb-3 grid gap-1 text-sm">@forelse ($project->members as $m)<li class="flex justify-between"><span>{{ $m->name }}</span><span class="text-muted">{{ $m->pivot->role }}</span></li>@empty<li class="text-muted">Only you.</li>@endforelse</ul>
                <form method="POST" action="{{ route('projects.members.store', $project) }}" class="grid gap-2">@csrf<input name="email" type="email" placeholder="colleague@example.com" class="input" required><div class="flex gap-2"><select name="role" class="input"><option value="viewer">Viewer</option><option value="editor">Editor</option></select><button class="btn-secondary">Add</button></div></form>
            </section>
            <x-ui.confirm :action="route('projects.destroy', $project)" method="DELETE" label="Delete project" icon="trash-2" message="This permanently deletes the project, its uploads, renders and deliveries. AI usage history is kept." class="btn-danger" />
        @endcan
    </div>
</div>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="grid gap-6 lg:col-span-2">
        @if ($project->workflow === 'preproduction' && ! $approved)
            @php $pre = $project->setting('preproduction', []); @endphp
            <section class="card card-pad">
                <div class="mb-1 flex items-center gap-2"><h2>Pre-production</h2><x-ui.badge tone="accent">Workflow B</x-ui.badge></div>
                <p class="mb-4 text-sm text-muted">Draft the story and narration, edit them, then approve the script. Locked production starts only after the script and the resulting package are approved.</p>
                <form method="POST" action="{{ route('preproduction.draft', $project) }}" class="grid gap-3" x-data="{ busy: false }" @submit="busy = true">
                    @csrf
                    <label class="label" for="idea">Story idea</label>
                    <textarea id="idea" name="idea" rows="4" class="input" required minlength="20">{{ old('idea', $project->setting('story_idea')) }}</textarea>
                    <div class="flex flex-wrap items-center gap-3"><button class="btn-secondary" :disabled="busy"><i data-lucide="sparkles" class="size-4"></i><span x-text="busy ? 'Drafting…' : '{{ empty($pre['story']) ? 'Generate draft' : 'Generate new draft' }}'"></span></button>
                        <label class="flex items-center gap-2 text-xs text-muted"><input type="checkbox" name="approved" value="1" class="accent-primary">Approve cost above the threshold</label></div>
                </form>
                @if (! empty($pre['story']))
                    <form method="POST" action="{{ route('preproduction.save', $project) }}" class="mt-6 grid gap-3 border-t border-line/60 pt-5">
                        @csrf @method('PUT')
                        @if (! empty($pre['origin']))<p class="text-xs text-muted">Draft by {{ $pre['origin'] }}.</p>@endif
                        <div><label class="label">Story</label><textarea name="story" rows="10" class="input font-mono text-xs" @disabled(! empty($pre['approved_at']))>{{ $pre['story'] }}</textarea></div>
                        <div><label class="label">Narration (exact words to be voiced)</label><textarea name="narration" rows="10" class="input" @disabled(! empty($pre['approved_at']))>{{ $pre['narration'] }}</textarea><p class="hint">{{ str_word_count($pre['narration'] ?? '') }} words ≈ {{ gmdate('i:s', (int) (str_word_count($pre['narration'] ?? '') / 2.6)) }} spoken.</p></div>
                        @if (empty($pre['approved_at']))
                            <div class="flex gap-2"><button class="btn-secondary" name="approve" value="0">Save draft</button>@can('approve', $project)<button class="btn-primary" name="approve" value="1">Approve script</button>@endcan</div>
                        @else
                            <p class="text-sm text-green-300"><i data-lucide="lock" class="inline size-4"></i> Script approved {{ \Illuminate\Support\Carbon::parse($pre['approved_at'])->diffForHumans() }}.</p>
                        @endif
                    </form>
                    @if (! empty($pre['approved_at']))
                        <form method="POST" action="{{ route('preproduction.package', $project) }}" enctype="multipart/form-data" class="mt-5 grid gap-3 border-t border-line/60 pt-5">
                            @csrf
                            <x-upload name="voiceover" label="Voiceover recorded from the approved narration" accept=".mp3,.wav,.m4a,.aac,.ogg,.flac" hint="Subtitles are timed from pauses in the voice (approximate) and validated against the narration." />
                            <input type="hidden" name="auto_produce" value="1">
                            <div><button class="btn-primary">Prepare production package</button></div>
                        </form>
                    @endif
                @endif
            </section>
        @endif

        <section class="card card-pad">
            <div class="mb-4 flex items-center justify-between"><h2>Production package {{ $package ? 'v'.$package->version : '' }}</h2>@if ($package)<x-ui.status :status="$package->status" />@endif</div>
            @if (! $package)
                <x-ui.empty icon="package" title="No package yet" text="Upload the approved story, narration, SRT and voiceover (or a ZIP containing them)." />
            @else
                @php $r = $package->validation_report; @endphp
                @if ($package->status === 'validating' || ($package->status === 'draft' && ! $r))
                    <div class="flex items-center gap-2 text-sm text-muted" x-data x-init="setTimeout(() => location.reload(), 4000)"><span class="size-2 animate-pulse rounded-full bg-accent"></span>Validating script, SRT and audio…</div>
                @endif
                @if ($r)
                    @if (! empty($r['auto_production_message']))<p class="mt-3 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm text-amber-200">Automatic production is waiting: {{ $r['auto_production_message'] }}</p>@endif
                    @if (! empty($r['srt_timing_adjustment']))<p class="mt-3 rounded-lg border border-primary/30 bg-primary/10 p-3 text-sm text-muted">Subtitle timings were automatically aligned to the voiceover. The subtitle words were kept unchanged; timing is approximate.</p>@endif
                    <ul class="grid gap-2">
                        @foreach ($r['checks'] as $c)
                            <li class="flex gap-3 rounded-lg bg-bg/50 px-3 py-2 text-sm"><x-ui.status :status="$c['status']" class="h-fit" /><div><div class="font-medium">{{ $c['label'] }}</div><div class="text-muted">{{ $c['message'] }}</div></div></li>
                        @endforeach
                    </ul>
                    @if (! empty($r['differences']))
                        <details class="mt-3 rounded-lg border border-warning/40 p-3 text-sm" open>
                            <summary class="cursor-pointer font-medium text-amber-200">Narration vs SRT differences ({{ count($r['differences']) }})</summary>
                            <p class="mt-2 text-xs text-muted">MotionForge never edits the approved words. Fix the SRT or confirm the difference is intended.</p>
                            <table class="table mt-2"><thead><tr><th>Type</th><th>Approved narration</th><th>SRT</th><th>Context</th></tr></thead><tbody>
                                @foreach ($r['differences'] as $d)<tr><td>{{ $d['type'] }}</td><td class="text-green-300">{{ $d['expected'] ?: '—' }}</td><td class="text-red-300">{{ $d['found'] ?: '—' }}</td><td class="text-xs text-muted">…{{ $d['context'] }}…</td></tr>@endforeach
                            </tbody></table>
                        </details>
                    @endif
                @endif
                @if ($package->voiceover)
                    <div class="mt-4"><div class="label">Voiceover</div><audio controls preload="none" src="{{ route('media.show', [$project, 'file', $package->voiceover_file_id]) }}" class="w-full"></audio></div>
                @endif
                <div class="mt-4 flex flex-wrap gap-2">
                    @unless ($package->isLocked())
                        <form method="POST" action="{{ route('packages.validate', [$project, $package]) }}">@csrf<button class="btn-secondary btn-sm"><i data-lucide="refresh-cw" class="size-4"></i>{{ ! empty($r['auto_produce']) ? 'Align subtitles and make video' : 'Re-validate' }}</button></form>
                    @endunless
                    @if ($package->status === 'valid')
                        @can('approve', $project)
                            <form method="POST" action="{{ route('packages.approve', [$project, $package]) }}" class="flex flex-wrap items-center gap-3" x-data="{ ack: false }">
                                @csrf
                                @if (collect($r['checks'] ?? [])->contains('status', 'warn'))<label class="flex items-center gap-2 text-sm text-amber-200"><input type="checkbox" name="acknowledge_warnings" value="1" x-model="ack" class="accent-primary">I reviewed the warnings and approve this package as-is</label>@endif
                                <button class="btn-primary btn-sm" @if (collect($r['checks'] ?? [])->contains('status', 'warn')) :disabled="!ack" @endif><i data-lucide="lock" class="size-4"></i>Approve and lock package</button>
                            </form>
                        @endcan
                    @elseif ($package->isApproved())
                        <p class="text-sm text-green-300"><i data-lucide="lock" class="inline size-4"></i> Approved {{ $package->approved_at->diffForHumans() }} by {{ $package->approver?->name }}. The story, narration, SRT and voice are locked (SHA-256 verified before every production job).</p>
                    @endif
                </div>
            @endif
        </section>

        @if ($package?->locked_story)
            <section class="card card-pad">
                <h2 class="mb-3">Locked story</h2>
                <div class="max-h-96 overflow-y-auto whitespace-pre-wrap rounded-lg bg-bg/50 p-4 font-mono text-xs text-muted">{{ $package->locked_story }}</div>
            </section>
        @endif
    </div>

    <aside class="grid h-fit gap-6">
        <section class="card card-pad">
            <h2 class="mb-1">{{ $package ? 'Upload a new version' : 'Upload package' }}</h2>
            <p class="mb-4 text-xs text-muted">{{ $package ? 'Only the files you upload are replaced; the rest carry over from v'.$package->version.'. A new version must be validated and approved again.' : 'Filenames may differ; files are mapped by type.' }}</p>
            <form method="POST" action="{{ route('packages.store', $project) }}" enctype="multipart/form-data" class="grid gap-3" x-data="{ busy: false }" @submit="busy = true">
                @csrf
                <input type="hidden" name="auto_produce" value="1">
                <x-upload name="story" label="Story (.md or .txt)" accept=".md,.txt" />
                <x-upload name="narration" label="Narration (.txt)" accept=".txt" />
                <x-upload name="srt" label="Subtitles (.srt)" accept=".srt" />
                <x-upload name="voiceover" label="Voiceover (.wav, .mp3, or audio file)" accept=".mp3,.wav,.m4a,.aac,.ogg,.flac" />
                <details class="rounded-lg border border-line p-3 text-sm">
                    <summary class="cursor-pointer text-muted">Have a ZIP package?</summary>
                    <x-upload name="archive" label="Package ZIP" accept=".zip" hint="The ZIP should contain the same four files." />
                </details>
                <button class="btn-primary" :disabled="busy"><span x-text="busy ? 'Uploading…' : 'Upload files and make video'"></span></button>
            </form>
        </section>
        @if ($files->where('role', 'character_ref')->isNotEmpty())
            <section class="card card-pad">
                <h2 class="mb-3">Character references</h2>
                <div class="grid grid-cols-3 gap-2">@foreach ($files->where('role', 'character_ref') as $f)<img src="{{ route('media.show', [$project, 'file', $f->id]) }}" alt="{{ $f->original_name }}" class="aspect-square rounded-lg object-cover">@endforeach</div>
            </section>
        @endif
    </aside>
</div>

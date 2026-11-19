<div class="grid gap-6">
    @php
        $isNative = $project->production_mode->value === 'native_video';
        $activeJobs = $jobs->whereIn('status', [\App\Enums\JobStatus::Queued, \App\Enums\JobStatus::Running]);
        $activeJob = $activeJobs->first();
        $latestStoryboardJob = $jobs->first(fn ($job) => $job->type === 'storyboard');
    @endphp
    <section class="card card-pad">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center">
            <div class="flex-1">
                <h2>Storyboard & scene generation</h2>
                <p class="mt-1 text-sm text-muted">
                    @if (! $approved && $project->production_mode->requiresLockedPackage())
                        Approve the production package first. Scenes are timed from the approved SRT and voice.
                    @else
                        Direction: <strong class="text-text">{{ $project->generation_strategy === 'ai' ? 'AI'.($estimate['model'] ? ' · '.$estimate['model'] : '') : 'Template director (no AI calls)' }}</strong>.
                        @if ($project->generation_strategy === 'ai')
                            Estimated: storyboard <x-ui.money :value="$estimate['storyboard']" />, all scene code <x-ui.money :value="$estimate['scenes']" /> (pre-call estimates from the pricing table).
                        @endif
                    @endif
                </p>
            </div>
            @if ($approved || ! $project->production_mode->requiresLockedPackage())
                <div class="flex flex-wrap gap-2" x-data="{ approved: false }">
                    @if ($approved)
                        <form method="POST" action="{{ route('storyboard.generate', $project) }}" x-data="{ busy: false }" @submit="busy = true">
                            @csrf <input type="hidden" name="continue" value="1"> <input type="hidden" name="approved" :value="approved ? 1 : 0">
                            @if ($scenes->isNotEmpty())<input type="hidden" name="replace" value="1">@endif
                            @if ($activeJobs->isNotEmpty())
                                <button type="button" class="btn-primary" disabled>
                                    <i data-lucide="loader-circle" class="size-4 animate-spin motion-reduce:animate-none"></i>
                                    Generation running…
                                </button>
                            @else
                                <button class="{{ $scenes->isEmpty() ? 'btn-primary' : 'btn-secondary' }}" :disabled="busy" @if ($scenes->isNotEmpty()) onclick="return confirm('Replace all scenes with a new storyboard? Existing scene versions are discarded.')" @endif>
                                    <i data-lucide="layout-template" class="size-4"></i>
                                    {{ $scenes->isEmpty() ? 'Generate storyboard + scenes' : 'Regenerate storyboard' }}
                                </button>
                            @endif
                        </form>
                    @endif
                    @if ($scenes->isNotEmpty() && ! $isNative)
                        <form method="POST" action="{{ route('scenes.generate', $project) }}">@csrf <input type="hidden" name="approved" :value="approved ? 1 : 0"><button class="btn-secondary"><i data-lucide="wand-sparkles" class="size-4"></i>Generate all scene animation</button></form>
                    @endif
                    @if ($project->generation_strategy === 'ai')<label class="flex items-center gap-2 text-xs text-muted"><input type="checkbox" x-model="approved" class="accent-primary">Approve operations above ${{ number_format(config('motionforge.ai.expensive_threshold_usd'), 2) }}</label>@endif
                </div>
            @endif
        </div>
        @if ($activeJobs->isNotEmpty())
            <div class="mt-4 grid gap-3 rounded-xl border border-primary/30 bg-primary/5 p-4" x-data x-init="setTimeout(() => location.reload(), 5000)" role="status" aria-live="polite">
                <div class="flex items-center gap-3">
                    <span class="grid size-11 shrink-0 place-items-center rounded-full bg-primary/15 text-primary"><i data-lucide="loader-circle" class="size-6 animate-spin motion-reduce:animate-none"></i></span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">Your video is being prepared</p>
                        <p class="text-sm text-muted">{{ $activeJobs->count() }} active job(s) · {{ $activeJob->stage }}</p>
                    </div>
                    <span class="hidden rounded-full bg-primary/10 px-3 py-1 text-xs text-primary sm:inline-flex">In progress</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-elevated"><div class="h-full w-1/3 rounded-full bg-primary motion-safe:animate-pulse"></div></div>
                @if ($activeJob->error && str_contains($activeJob->error, 'HTTP 503'))
                    <p class="text-sm text-amber-200">The AI provider is temporarily busy. MotionForge will retry automatically.</p>
                @else
                    <p class="text-xs text-muted">This page refreshes automatically while generation runs.</p>
                @endif
            </div>
        @endif
        @if ($scenes->isEmpty() && $activeJobs->isEmpty() && $latestStoryboardJob?->status === \App\Enums\JobStatus::Failed)
            <div class="mt-4 flex flex-col gap-3 rounded-xl border border-error/40 bg-error/10 p-4 sm:flex-row sm:items-center" role="alert">
                <div class="flex-1">
                    <p class="font-medium text-red-200">Storyboard generation failed</p>
                    <p class="mt-1 text-sm text-red-200/80">
                        @if ($latestStoryboardJob->error && str_contains($latestStoryboardJob->error, 'HTTP 503'))
                            The AI provider is temporarily overloaded. The automatic retries have ended; try again when the provider is available.
                        @else
                            {{ $latestStoryboardJob->error ?: 'The storyboard could not be generated.' }}
                        @endif
                    </p>
                </div>
                <form method="POST" action="{{ route('jobs.retry', $latestStoryboardJob) }}">
                    @csrf
                    <button class="btn-secondary btn-sm">Retry storyboard</button>
                </form>
            </div>
        @endif
        @foreach ($jobs->where('status', \App\Enums\JobStatus::WaitingApproval)->take(3) as $j)
            <div class="mt-3 flex flex-wrap items-center gap-3 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm"><span class="flex-1 text-amber-100">{{ $j->error }}</span><form method="POST" action="{{ route('jobs.retry', $j) }}">@csrf<input type="hidden" name="approve" value="1"><button class="btn-primary btn-sm">Approve and continue</button></form></div>
        @endforeach
    </section>

    @if ($isNative && ! $approved)
        <section class="card card-pad">
            <h2 class="mb-3">Add a clip scene</h2>
            <form method="POST" action="{{ route('scenes.store', $project) }}" class="grid gap-3 sm:grid-cols-[1fr_8rem_auto] sm:items-end">@csrf
                <div><label class="label">Video prompt</label><input name="prompt" class="input" required minlength="10" placeholder="Slow push-in on a boy in a red cap staring at a glowing phone in a dark hallway"></div>
                <div><label class="label">Seconds</label><input name="duration" type="number" min="3" max="15" step="0.5" value="6" class="input"></div>
                <button class="btn-primary">Add scene</button>
            </form>
            <p class="hint">Clip length follows the selected model’s limits. Character consistency and exact timing are not guaranteed by video models.</p>
        </section>
    @endif

    @if ($scenes->isEmpty())
        <x-ui.empty icon="clapperboard" title="No scenes yet" text="Generate the storyboard to create timed scenes from the approved package." />
    @else
        @php $fails = $scenes->where('status', 'failed'); @endphp
        @if ($fails->isNotEmpty())
            <div class="rounded-lg border border-error/40 bg-error/10 p-3 text-sm text-red-200">{{ $fails->count() }} scene(s) failed. Open them in the editor to fix or regenerate only those scenes.</div>
        @endif
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ($scenes as $s)
                @php $prev = $s->assets->first(fn ($a) => $a->type === 'preview_png' && $a->scene_version_id === $s->current_version_id); $plan = $s->currentVersion?->plan ?? []; @endphp
                <a href="{{ route('editor.show', [$project, 'scene' => $s->id]) }}" class="card group overflow-hidden transition hover:border-primary/60">
                    <div class="relative aspect-[9/16] bg-elevated">
                        @if ($prev)<img loading="lazy" src="{{ route('media.show', [$project, 'scene-asset', $prev->id]) }}" alt="Scene {{ $s->sequence }} preview" class="size-full object-cover">
                        @else<div class="grid size-full place-items-center p-3 text-center text-xs text-muted">{{ $s->status === 'generating' ? 'Generating…' : 'No preview' }}</div>@endif
                        <div class="absolute left-2 top-2"><x-ui.status :status="$s->status" /></div>
                        <span class="absolute right-2 top-2 rounded bg-black/60 px-1.5 py-0.5 font-mono text-[11px]">#{{ $s->sequence }}</span>
                    </div>
                    <div class="p-3">
                        <div class="font-mono text-[11px] text-muted">{{ number_format($s->start_time, 1) }}–{{ number_format($s->end_time, 1) }} s · {{ str_replace('_', ' ', $plan['camera']['shot'] ?? '') }}</div>
                        <p class="mt-1 line-clamp-2 text-xs">{{ $s->kind === 'end_card' ? 'End card' : $s->narration_reference }}</p>
                        @if ($s->error)<p class="mt-1 line-clamp-2 text-xs text-red-300">{{ $s->error }}</p>@endif
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>

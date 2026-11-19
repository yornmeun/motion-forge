@if (! $final)
    <x-ui.empty icon="package-open" title="Nothing delivered yet" text="When a render finishes you get final.mp4, thumbnail.png and a ready-to-post caption here." />
@else
    <div class="grid gap-6 lg:grid-cols-[minmax(0,22rem)_1fr]">
        <div>
            <video controls preload="metadata" playsinline class="aspect-[9/16] w-full rounded-xl border border-line bg-black" @if($thumbnail) poster="{{ route('media.show', [$project, 'thumbnail', $thumbnail->id]) }}" @endif src="{{ route('media.show', [$project, 'artifact', $final->id]) }}"></video>
            <div class="mt-3 grid grid-cols-2 gap-2">
                <a href="{{ route('delivery.download', [$project, 'video']) }}" class="btn-primary"><i data-lucide="download" class="size-4"></i>final.mp4</a>
                <a href="{{ route('delivery.download', [$project, 'thumbnail']) }}" class="btn-secondary @if(! $thumbnail) pointer-events-none opacity-50 @endif"><i data-lucide="image" class="size-4"></i>thumbnail.png</a>
            </div>
            <p class="mt-2 text-xs text-muted">{{ number_format($final->size / 1048576, 1) }} MB · rendered {{ $final->created_at->diffForHumans() }} · <a class="underline" href="{{ route('delivery.download', [$project, 'srt']) }}">SRT</a></p>
        </div>
        <div class="grid h-fit gap-6">
            @if ($caption)
                <section class="card card-pad" x-data="{ copied: false, text: @js($caption->postText()) }">
                    <div class="mb-3 flex items-center justify-between"><h2>{{ $caption->isYoutubeBlock() ? 'YouTube text' : 'Post caption' }}</h2><button class="btn-secondary btn-sm" @click="navigator.clipboard.writeText(text); copied = true; setTimeout(() => copied = false, 2000)"><i data-lucide="copy" class="size-4"></i><span x-text="copied ? 'Copied' : 'Copy'"></span></button></div>
                    <p class="whitespace-pre-line">{{ $caption->body }}</p>
                    @unless ($caption->isYoutubeBlock())<p class="mt-2 text-indigo-300">{{ implode(' ', $caption->hashtags) }}</p>@endunless
                    <p class="mt-3 text-xs text-muted">Written by {{ $caption->origin }}.</p>
                </section>
            @endif
            @if ($thumbnail)
                <section class="card card-pad">
                    <h2 class="mb-3">Thumbnail checks</h2>
                    <div class="flex items-end gap-4">
                        @if ($project->isLandscape())
                            <figure><img src="{{ route('media.show', [$project, 'thumbnail', $thumbnail->id]) }}" class="w-64 rounded-lg" alt="Thumbnail"><figcaption class="mt-1 text-xs text-muted">1280×720</figcaption></figure>
                            <figure><img src="{{ route('media.show', [$project, 'thumb-check', 'x']) }}?v=medium" class="w-40 rounded" alt="Medium"><figcaption class="mt-1 text-xs text-muted">640×360</figcaption></figure>
                            <figure><img src="{{ route('media.show', [$project, 'thumb-check', 'x']) }}" class="w-[84px] rounded" alt="Small"><figcaption class="mt-1 text-xs text-muted">168×94 (mobile)</figcaption></figure>
                        @else
                            <figure><img src="{{ route('media.show', [$project, 'thumbnail', $thumbnail->id]) }}" class="w-32 rounded-lg" alt="Thumbnail"><figcaption class="mt-1 text-xs text-muted">1080×1920</figcaption></figure>
                            <figure><img src="{{ route('media.show', [$project, 'thumb-check', 'x']) }}" class="w-[68px] rounded" alt="Small"><figcaption class="mt-1 text-xs text-muted">270×480</figcaption></figure>
                            <figure><img src="{{ route('media.show', [$project, 'thumb-check', 'x']) }}?v=crop" class="w-24 rounded" alt="3:4 crop"><figcaption class="mt-1 text-xs text-muted">3:4 crop</figcaption></figure>
                        @endif
                    </div>
                </section>
            @endif
            <section class="card card-pad">
                <h2 class="mb-3">Technical quality control</h2>
                <ul class="grid gap-2 text-sm">
                    @forelse ($checks['technical'] ?? [] as $c)
                        <li class="flex items-start gap-3"><x-ui.status :status="$c->status" /><div class="flex-1"><span class="font-medium">{{ str_replace('_', ' ', ucfirst($c->check_key)) }}</span> <span class="text-muted">{{ $c->value }}@if($c->expected) (expected {{ $c->expected }})@endif</span><div class="text-xs text-muted">{{ $c->message }}</div></div></li>
                    @empty<li class="text-muted">Not run yet.</li>@endforelse
                </ul>
            </section>
            <section class="card card-pad">
                <h2 class="mb-1">Creative review</h2>
                <p class="mb-3 text-xs text-muted">Human judgement, kept separate from technical checks.</p>
                <ul class="grid gap-2">
                    @foreach ($checks['creative'] ?? [] as $c)
                        <li class="flex flex-wrap items-center gap-3 rounded-lg bg-bg/50 px-3 py-2 text-sm">
                            <span class="flex-1">{{ $c->message }}</span>
                            <form method="POST" action="{{ route('quality.review', [$project, $c]) }}" class="flex gap-1">@csrf
                                @foreach (['pass' => 'Pass', 'fail' => 'Needs work'] as $v => $l)<button name="status" value="{{ $v }}" class="btn-sm {{ $c->status === $v ? ($v === 'pass' ? 'btn bg-success/20 text-green-200' : 'btn bg-error/20 text-red-200') : 'btn-ghost' }}">{{ $l }}</button>@endforeach
                            </form>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    </div>
@endif

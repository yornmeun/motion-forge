<x-layouts.app title="Create Video">
    <x-slot:header><h1 class="text-lg">Create a video</h1></x-slot:header>

    @php
        $hasAi = collect($credentials)->contains(fn ($credential) => $credential['usable']);
        $hasVideo = collect($videoModels)->flatten(1)->isNotEmpty();
    @endphp

    <form method="POST" action="{{ route('projects.store') }}" enctype="multipart/form-data" class="mx-auto grid max-w-3xl gap-6" x-data="{ busy: false, source: @js(old('source', 'zip')), format: @js(old('resolution', '1920x1080')) }" @submit="busy = true">
        @csrf
        <input type="hidden" name="workflow" value="approved_package">
        <input type="hidden" name="aspect_ratio" :value="format === '1920x1080' ? '16:9' : '9:16'">
        <input type="hidden" name="resolution" :value="format">
        <input type="hidden" name="auto_produce" value="1">

        <section class="card card-pad grid gap-5">
            <div>
                <h2>What is your video called?</h2>
                <p class="mt-1 text-sm text-muted">Made as a YouTube video (16:9, 1920×1080, 30 fps). Production starts after your files pass validation.</p>
            </div>
            <div>
                <label class="label" for="title">Video title</label>
                <input id="title" name="title" value="{{ old('title') }}" required maxlength="160" class="input" placeholder="The Day I Stopped Explaining Myself">
                @error('title')<p class="hint text-red-300">{{ $message }}</p>@enderror
            </div>
        </section>

        <section class="card card-pad grid gap-5">
            <div>
                <h2>Add the production package</h2>
                <p class="mt-1 text-sm text-muted">The approved package from ChatGPT. The scene sheet, registries and Kiki reference are followed as written; the story, narration and voice are never changed.</p>
            </div>
            <div class="flex gap-2" role="radiogroup" aria-label="Package source">
                <label class="btn-secondary btn-sm cursor-pointer" :class="source === 'zip' && 'ring-2 ring-primary'"><input type="radio" name="source" value="zip" x-model="source" class="sr-only">Package ZIP</label>
                <label class="btn-secondary btn-sm cursor-pointer" :class="source === 'files' && 'ring-2 ring-primary'"><input type="radio" name="source" value="files" x-model="source" class="sr-only">Separate files</label>
            </div>

            <template x-if="source === 'zip'">
                <div class="grid gap-5">
                    <x-upload name="archive" label="Package (.zip)" accept=".zip" required hint="STORY_LOCKED, NARRATION, STORY.srt, SCENE_SHEET, registries, KIKI_REFERENCE, VIDEO_EXECUTION. File names may differ." />
                </div>
            </template>
            <template x-if="source === 'files'">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-upload name="story" label="Locked story (.md or .txt)" accept=".md,.txt" required />
                    <x-upload name="narration" label="Narration (.txt)" accept=".txt" required />
                    <x-upload name="srt" label="Subtitles (.srt)" accept=".srt" required />
                    <x-upload name="scene_sheet" label="Scene sheet (.md)" accept=".md,.txt" />
                    <x-upload name="character_registry" label="Character registry (.md)" accept=".md,.txt" />
                    <x-upload name="background_registry" label="Background registry (.md)" accept=".md,.txt" />
                    <x-upload name="kiki_reference" label="Kiki reference (.md)" accept=".md,.txt" />
                    <x-upload name="video_execution" label="Video execution (.md)" accept=".md,.txt" />
                </div>
            </template>

            <x-upload name="voiceover" label="Narration audio (.wav, .mp3, or audio file)" accept=".mp3,.wav,.m4a,.aac,.ogg,.flac" hint="Required unless the ZIP includes it." />
            <p class="hint">Estimated SRT timings are retimed to the real narration; subtitle words stay exactly as approved.</p>
            @error('package')<p class="hint text-red-300">{{ $message }}</p>@enderror
            @error('voiceover')<p class="hint text-red-300">{{ $message }}</p>@enderror
        </section>

        <details class="card card-pad grid gap-4">
            <summary class="cursor-pointer font-medium">Advanced options</summary>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="production_mode">Video style</label>
                    <select id="production_mode" name="production_mode" class="input">
                        <option value="kikitoons" @selected(old('production_mode', 'kikitoons') === 'kikitoons')>Kikitoons animation</option>
                        @if ($hasVideo)
                            <option value="native_video" @selected(old('production_mode') === 'native_video')>AI generated clips</option>
                            <option value="hybrid" @selected(old('production_mode') === 'hybrid')>Animation with AI clips</option>
                        @endif
                    </select>
                </div>
                <div>
                    <label class="label" for="generation_strategy">Scene direction</label>
                    <select id="generation_strategy" name="generation_strategy" class="input">
                        <option value="template" @selected(old('generation_strategy', 'template') === 'template')>Built-in director (free)</option>
                        @if ($hasAi)<option value="ai" @selected(old('generation_strategy') === 'ai')>AI direction (uses API credits)</option>@endif
                    </select>
                </div>
                <div>
                    <label class="label" for="frame_rate">Frame rate</label>
                    <select id="frame_rate" name="frame_rate" class="input">
                        <option value="30" @selected((string) old('frame_rate', '30') === '30')>30 fps (Kikitoons standard)</option>
                        <option value="24" @selected((string) old('frame_rate') === '24')>24 fps (renders ~20% faster)</option>
                    </select>
                </div>
                <div>
                    <label class="label" for="format">Format</label>
                    <select id="format" x-model="format" class="input">
                        <option value="1920x1080">YouTube 16:9 (1920×1080)</option>
                        <option value="1080x1920">Vertical 9:16 (1080×1920)</option>
                    </select>
                </div>
            </div>
            <p class="hint">Most videos work with the defaults. The built-in director makes no paid API calls and follows the scene sheet. Make a vertical version only when you need one.</p>
        </details>

        <div class="flex justify-end">
            <button class="btn-primary" :disabled="busy"><span x-text="busy ? 'Uploading…' : 'Create video' "></span></button>
        </div>
    </form>
</x-layouts.app>

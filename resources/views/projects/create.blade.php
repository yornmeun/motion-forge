<x-layouts.app title="Create Video">
    <x-slot:header><h1 class="text-lg">Create video</h1></x-slot:header>
    <form method="POST" action="{{ route('projects.store') }}" enctype="multipart/form-data"
          x-data="wizard(@js(['credentials' => $credentials, 'textModels' => $textModels, 'videoModels' => $videoModels, 'old' => old()]))" @submit="submitting = true">
        @csrf
        {{-- stepper --}}
        <ol class="mb-6 grid grid-cols-5 gap-2" aria-label="Steps">
            <template x-for="(s, i) in steps" :key="i">
                <li><button type="button" @click="go(i)" class="w-full text-left" :aria-current="step === i ? 'step' : null">
                    <div class="h-1 rounded-full" :class="i <= step ? 'bg-primary' : 'bg-elevated'"></div>
                    <div class="mt-2 text-xs" :class="i === step ? 'text-text' : 'text-muted'"><span class="font-mono" x-text="i + 1"></span>. <span x-text="s"></span></div>
                </button></li>
            </template>
        </ol>

        <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
            <div class="card card-pad">
                {{-- Step 1 --}}
                <section x-show="step === 0" class="grid gap-4">
                    <h2>Project information</h2>
                    <div><label class="label" for="title">Project title</label><input id="title" name="title" x-model="f.title" required maxlength="160" class="input" placeholder="I Got My Principal Fired With a WiFi Password"></div>
                    <div><label class="label" for="description">Description / hook</label><textarea id="description" name="description" x-model="f.description" rows="2" class="input" maxlength="2000"></textarea></div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="label" for="genre">Genre</label><input id="genre" name="genre" x-model="f.genre" class="input" placeholder="Thriller, drama, mystery…"></div>
                        <div><label class="label" for="target">Target duration (s)</label><input id="target" name="target_duration_seconds" x-model="f.target_duration_seconds" type="number" min="10" max="900" class="input" placeholder="Set by the voiceover"></div>
                    </div>
                    <div>
                        <div class="label">Production mode</div>
                        <div class="grid gap-3 sm:grid-cols-3">
                            @foreach ($modes as $m)
                                <label class="cursor-pointer rounded-lg border p-3 transition" :class="f.production_mode === '{{ $m->value }}' ? 'border-primary bg-primary/10' : 'border-line hover:border-muted'">
                                    <input type="radio" name="production_mode" value="{{ $m->value }}" x-model="f.production_mode" class="sr-only">
                                    <div class="text-sm font-medium">{{ $m->label() }}</div><p class="mt-1 text-xs text-muted">{{ $m->description() }}</p>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div><label class="label" for="ar">Aspect ratio</label><select id="ar" name="aspect_ratio" x-model="f.aspect_ratio" @change="syncResolution()" class="input"><option>9:16</option><option>16:9</option><option>1:1</option></select></div>
                        <div><label class="label" for="res">Resolution</label><select id="res" name="resolution" x-model="f.resolution" class="input"><template x-for="r in resolutions()" :key="r"><option :value="r" x-text="r.replace('x', ' × ')"></option></template></select></div>
                        <div><label class="label" for="fps">Frame rate</label><select id="fps" name="frame_rate" x-model="f.frame_rate" class="input"><option value="30">30 fps</option><option value="25">25 fps</option><option value="24">24 fps</option></select></div>
                    </div>
                    <p class="hint" x-show="f.production_mode === 'kikitoons'">Kikitoons default: 9:16, 1080 × 1920, 30 fps; length is set by the approved narration (typically 2:10–2:40).</p>
                </section>

                {{-- Step 2 --}}
                <section x-show="step === 1" x-cloak class="grid gap-4">
                    <h2>AI configuration</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="cursor-pointer rounded-lg border p-3" :class="f.generation_strategy === 'ai' ? 'border-primary bg-primary/10' : 'border-line'"><input type="radio" class="sr-only" name="generation_strategy" value="ai" x-model="f.generation_strategy"><div class="text-sm font-medium">AI direction</div><p class="mt-1 text-xs text-muted">A connected model plans scenes and writes animation code. Uses your API credits.</p></label>
                        <label class="cursor-pointer rounded-lg border p-3" :class="f.generation_strategy === 'template' ? 'border-primary bg-primary/10' : 'border-line'"><input type="radio" class="sr-only" name="generation_strategy" value="template" x-model="f.generation_strategy"><div class="text-sm font-medium">Template director</div><p class="mt-1 text-xs text-muted">Built-in rules stage every scene. No AI calls, no cost; simpler direction.</p></label>
                    </div>
                    <template x-if="!credentials.length"><p class="rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm text-amber-200">No AI connection yet. <a class="underline" href="{{ route('providers.index') }}">Connect a provider</a> or use the template director.</p></template>
                    <div class="grid gap-4 sm:grid-cols-2" x-show="f.production_mode !== 'native_video' || true">
                        <div><label class="label" for="cred">Planning & code provider</label>
                            <select id="cred" name="ai_credential_id" x-model="f.ai_credential_id" class="input"><option value="">Automatic (first connected)</option><template x-for="c in credentials" :key="c.id"><option :value="c.id" x-text="c.name + ' — ' + c.provider + (c.usable ? '' : ' (not connected)')"></option></template></select></div>
                        <div><label class="label" for="model">Model</label>
                            <select id="model" name="ai_model" x-model="f.ai_model" class="input" :disabled="!f.ai_credential_id"><option value="">Connection default</option><template x-for="m in (textModels[f.ai_credential_id] || [])" :key="m.id"><option :value="m.id" x-text="m.name + (m.in !== null ? ` ($${m.in}/$${m.out} per 1M)` : ' (price n/a)')"></option></template></select></div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2" x-show="f.production_mode !== 'kikitoons'">
                        <div><label class="label" for="vcred">Video generation provider</label>
                            <select id="vcred" name="video_credential_id" x-model="f.video_credential_id" class="input"><option value="">None</option><template x-for="c in credentials.filter(c => (videoModels[c.id] || []).length)" :key="c.id"><option :value="c.id" x-text="c.name + ' — ' + c.provider"></option></template></select>
                            <p class="hint">Only connections with a verified native video model are listed.</p></div>
                        <div><label class="label" for="vmodel">Video model</label>
                            <select id="vmodel" name="video_model" x-model="f.video_model" class="input"><option value="">Default</option><template x-for="m in (videoModels[f.video_credential_id] || [])" :key="m.id"><option :value="m.id" x-text="m.name + (m.video !== null ? ` (~$${m.video}/s)` : '')"></option></template></select></div>
                    </div>
                    <div><label class="label" for="budget">Project budget (USD)</label><input id="budget" name="budget_limit_usd" x-model="f.budget_limit_usd" type="number" step="0.01" min="0" class="input" placeholder="No limit"><p class="hint">AI calls that would exceed it stop and ask for approval.</p></div>
                    <div class="rounded-lg border border-line p-3">
                        <input type="hidden" name="fallback_enabled" value="0">
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="fallback_enabled" value="1" x-model="f.fallback_enabled" class="accent-primary">Use a fallback provider if the primary one is unavailable</label>
                        <div class="mt-3 grid gap-3 sm:grid-cols-2" x-show="f.fallback_enabled">
                            <select name="fallback_credential_id" x-model="f.fallback_credential_id" class="input"><option value="">Choose connection</option><template x-for="c in credentials" :key="c.id"><option :value="c.id" x-text="c.name + ' — ' + c.provider"></option></template></select>
                            <select name="fallback_model" x-model="f.fallback_model" class="input"><option value="">Connection default</option><template x-for="m in (textModels[f.fallback_credential_id] || [])" :key="m.id"><option :value="m.id" x-text="m.name"></option></template></select>
                        </div>
                        <p class="hint">The fallback may have different prices and data policies; it is used only when you enable it here.</p>
                    </div>
                </section>

                {{-- Step 3 --}}
                <section x-show="step === 2" x-cloak class="grid gap-4">
                    <h2>Production materials</h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="cursor-pointer rounded-lg border p-3" :class="f.workflow === 'approved_package' ? 'border-primary bg-primary/10' : 'border-line'"><input type="radio" class="sr-only" name="workflow" value="approved_package" x-model="f.workflow"><div class="text-sm font-medium">Approved package</div><p class="mt-1 text-xs text-muted">Locked story, narration, SRT and voiceover are ready. Production follows them exactly.</p></label>
                        <label class="cursor-pointer rounded-lg border p-3" :class="f.workflow === 'preproduction' ? 'border-primary bg-primary/10' : 'border-line'"><input type="radio" class="sr-only" name="workflow" value="preproduction" x-model="f.workflow"><div class="text-sm font-medium">Pre-production</div><p class="mt-1 text-xs text-muted">Start from an idea, draft and approve the script first. Production waits for approval.</p></label>
                    </div>
                    <template x-if="f.workflow === 'approved_package'">
                        <div class="grid gap-3">
                            <x-upload name="archive" label="Package ZIP (optional)" accept=".zip" hint="Filenames may differ; files are mapped by type. Or upload them individually below." />
                            <div class="grid gap-3 sm:grid-cols-2">
                                <x-upload name="story" label="Locked story (.md / .txt)" accept=".md,.txt" />
                                <x-upload name="narration" label="Approved narration (.txt)" accept=".txt" />
                                <x-upload name="srt" label="Subtitles (.srt)" accept=".srt" />
                                <x-upload name="voiceover" label="Voiceover audio" accept=".mp3,.wav,.m4a,.aac,.ogg,.flac" />
                            </div>
                            <x-upload name="characters[]" label="Character references (optional)" accept="image/png,image/jpeg,image/webp" multiple hint="Kiki is always drawn with the engine preset; references guide side characters." />
                        </div>
                    </template>
                    <template x-if="f.workflow === 'preproduction'">
                        <div><label class="label" for="idea">Story idea</label><textarea id="idea" name="settings[story_idea]" x-model="f.story_idea" rows="6" class="input" placeholder="What happens, who is involved, how it ends…"></textarea><p class="hint">After creating the project you can generate a draft, edit it, and approve the final script. Locked production starts only after approval and a voiceover upload.</p></div>
                    </template>
                </section>

                {{-- Step 4 --}}
                <section x-show="step === 3" x-cloak class="grid gap-4">
                    <h2>Production settings</h2>
                    <p class="text-sm text-muted">Kikitoons cinematic defaults: serious tone, restrained acting, slow push-ins, muted grade with one accent colour, cinematic sound design.</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label class="label">Animation style</label><select name="settings[animation_style]" class="input"><option value="cinematic">Cinematic (default)</option><option value="restrained">Restrained</option><option value="dynamic">Dynamic</option></select></div>
                        <div><label class="label">Camera movement</label><select name="settings[camera]" class="input"><option value="mixed">Motivated mix (default)</option><option value="slow_push_in">Mostly slow push-ins</option><option value="handheld_drift">Handheld drift</option><option value="static_drift">Minimal drift</option></select></div>
                        <div><label class="label">Background atmosphere</label><select name="settings[atmosphere]" class="input">@foreach (\App\Services\Production\PlanVocabulary::LOOKS as $l)<option value="{{ $l }}">{{ ucfirst($l) }}</option>@endforeach</select></div>
                        <div><label class="label">Sound design</label><select name="settings[sound]" class="input"><option value="cinematic">Cinematic (default)</option><option value="minimal">Minimal</option><option value="music_forward">Music forward</option></select></div>
                        <div><label class="label">Subtitle position (y px)</label><input name="settings[subtitles][y]" type="number" min="220" max="1450" value="1344" class="input"><p class="hint">Safe zone 220–1450.</p></div>
                        <div><label class="label">Subtitle style</label><select name="settings[subtitles][style]" class="input"><option value="serious">Serious fade/rise (default)</option><option value="bold">Bold</option></select></div>
                    </div>
                    <div><label class="label">Thumbnail title (optional)</label><input name="settings[thumbnail][title]" maxlength="40" class="input" placeholder="2–5 words, e.g. I GOT HIM FIRED?!"><p class="hint">Leave empty to generate it with the caption.</p></div>
                </section>

                {{-- Step 5 --}}
                <section x-show="step === 4" x-cloak class="grid gap-4">
                    <h2>Review</h2>
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div><dt class="label">Title</dt><dd x-text="f.title || '—'"></dd></div>
                        <div><dt class="label">Mode</dt><dd x-text="modeLabel()"></dd></div>
                        <div><dt class="label">Output</dt><dd x-text="f.resolution.replace('x', ' × ') + ' · ' + f.aspect_ratio + ' · ' + f.frame_rate + ' fps · H.264 + AAC'"></dd></div>
                        <div><dt class="label">Direction</dt><dd x-text="f.generation_strategy === 'ai' ? 'AI · ' + credLabel(f.ai_credential_id) + (f.ai_model ? ' · ' + f.ai_model : '') : 'Template director (no AI calls)'"></dd></div>
                        <div><dt class="label">Budget</dt><dd x-text="f.budget_limit_usd ? '$' + f.budget_limit_usd : 'No limit'"></dd></div>
                        <div><dt class="label">Workflow</dt><dd x-text="f.workflow === 'approved_package' ? 'Approved package' : 'Pre-production'"></dd></div>
                    </dl>
                    <div class="rounded-lg bg-elevated p-3 text-sm">
                        <div class="label">Validation</div>
                        <ul class="grid gap-1"><template x-for="p in problems()" :key="p"><li class="flex gap-2 text-amber-200"><i data-lucide="circle-alert" class="mt-0.5 size-4"></i><span x-text="p"></span></li></template>
                            <li x-show="!problems().length" class="text-green-300">Ready to create. Uploaded materials are validated right after creation; production starts only after you approve the package.</li></ul>
                    </div>
                </section>

                <div class="mt-6 flex justify-between border-t border-line/60 pt-4">
                    <button type="button" class="btn-ghost" @click="step--" x-show="step > 0">Back</button><span x-show="step === 0"></span>
                    <button type="button" class="btn-primary" @click="next()" x-show="step < 4">Continue</button>
                    <button class="btn-primary" x-show="step === 4" :disabled="submitting || blocking()"><span x-text="submitting ? 'Creating…' : 'Create project'"></span></button>
                </div>
            </div>
            <aside class="card card-pad h-fit text-sm text-muted">
                <h2 class="mb-2 text-text">How production works</h2>
                <ol class="grid list-decimal gap-2 pl-4">
                    <li>Validate and approve the package (story, narration, SRT, voice).</li>
                    <li>Generate the storyboard: scene timings come from the real SRT and voice.</li>
                    <li>Generate animation code per scene; review previews; fix or regenerate.</li>
                    <li>Render: scenes, sound design, FFmpeg assembly, QC.</li>
                    <li>Download final.mp4, thumbnail.png and the caption.</li>
                </ol>
                <p class="mt-4">The approved narration and voice are never rewritten. Mismatches are reported for you to decide.</p>
            </aside>
        </div>
    </form>
    <script>
        function wizard(init) {
            const o = init.old || {};
            return {
                step: 0, submitting: false, steps: ['Project', 'AI', 'Materials', 'Settings', 'Review'],
                credentials: init.credentials, textModels: init.textModels, videoModels: init.videoModels,
                f: {
                    title: o.title || '', description: o.description || '', genre: o.genre || '', target_duration_seconds: o.target_duration_seconds || '',
                    production_mode: o.production_mode || 'kikitoons', aspect_ratio: o.aspect_ratio || '9:16', resolution: o.resolution || '1080x1920', frame_rate: o.frame_rate || '30',
                    generation_strategy: o.generation_strategy || (init.credentials.some(c => c.usable) ? 'ai' : 'template'),
                    ai_credential_id: o.ai_credential_id || '', ai_model: o.ai_model || '', video_credential_id: o.video_credential_id || '', video_model: o.video_model || '',
                    fallback_enabled: !!+o.fallback_enabled, fallback_credential_id: o.fallback_credential_id || '', fallback_model: o.fallback_model || '',
                    budget_limit_usd: o.budget_limit_usd || '', workflow: o.workflow || 'approved_package', story_idea: '',
                },
                resolutions() { return { '9:16': ['1080x1920', '720x1280'], '16:9': ['1920x1080'], '1:1': ['1080x1080'] }[this.f.aspect_ratio]; },
                syncResolution() { this.f.resolution = this.resolutions()[0]; },
                modeLabel() { return { kikitoons: 'Kikitoons 2D Animation', native_video: 'Native AI Video', hybrid: 'Hybrid AI Production' }[this.f.production_mode]; },
                credLabel(id) { const c = this.credentials.find(c => c.id === id); return c ? c.name : 'automatic'; },
                problems() {
                    const p = [];
                    if (!this.f.title.trim()) p.push('A title is required.');
                    if (this.f.generation_strategy === 'ai' && !this.credentials.some(c => c.usable)) p.push('AI direction needs a connected provider (or choose the template director).');
                    if (this.f.production_mode !== 'kikitoons' && !Object.values(this.videoModels).some(v => v.length)) p.push('No connected provider has a verified native video model; AI clips will be unavailable until you connect one.');
                    if (this.f.fallback_enabled && !this.f.fallback_credential_id) p.push('Choose the fallback connection or disable fallback.');
                    return p;
                },
                blocking() { return !this.f.title.trim() || (this.f.fallback_enabled && !this.f.fallback_credential_id); },
                go(i) { if (i < this.step || this.f.title.trim()) this.step = i; },
                next() { if (this.step === 0 && !this.f.title.trim()) { toast('Enter a project title first.', 'error'); return; } this.step++; this.$nextTick(() => window.mfIcons()); },
            };
        }
    </script>
</x-layouts.app>

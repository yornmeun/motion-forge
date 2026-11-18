<x-layouts.app :title="'Editor · '.$project->title" :wide="true">
    <x-slot:header>
        <div class="flex min-w-0 items-center gap-3">
            <a href="{{ route('projects.show', [$project, 'tab' => 'scenes']) }}" class="btn-ghost btn-sm" aria-label="Back"><i data-lucide="arrow-left" class="size-4"></i></a>
            <span class="font-semibold">MotionForge</span><span class="text-muted">|</span><span class="truncate">{{ $project->title }}</span>
        </div>
    </x-slot:header>

    <div x-data="sceneEditor(@js(['state' => route('editor.state', $project), 'base' => url('/projects/'.$project->id), 'canEdit' => $canEdit, 'initial' => request('scene'), 'render' => route('render.start', $project), 'csrf' => csrf_token()]))" x-init="load()"
         @keydown.window="keys($event)" class="flex h-[calc(100vh-4rem)] flex-col">
        {{-- toolbar --}}
        <div class="flex items-center gap-2 border-b border-line/60 bg-sidebar px-4 py-2">
            <button class="btn-ghost btn-sm" @click="togglePlay()" :aria-label="playing ? 'Pause' : 'Play'"><i :data-lucide="playing ? 'pause' : 'play'" class="size-4"></i><span x-text="playing ? 'Pause' : 'Preview'"></span></button>
            <span class="font-mono text-xs text-muted" x-text="fmt(time) + ' / ' + fmt(sceneDuration())"></span>
            <div class="mx-2 h-5 w-px bg-line"></div>
            <button class="btn-ghost btn-sm" @click="undo()" :disabled="!canUndo()" title="Undo (Ctrl+Z)"><i data-lucide="undo-2" class="size-4"></i></button>
            <button class="btn-ghost btn-sm" @click="redo()" :disabled="!canRedo()" title="Redo (Ctrl+Y)"><i data-lucide="redo-2" class="size-4"></i></button>
            <span class="ml-2 text-xs text-muted" x-show="busy" x-text="busy"></span>
            <div class="ml-auto flex gap-2">
                <button class="btn-secondary btn-sm" @click="renderScene()" x-show="scene && canEdit" :disabled="!sceneRenderable()"><i data-lucide="clapperboard" class="size-4"></i>Render scene</button>
                @can('produce', $project)<form method="POST" action="{{ route('render.start', $project) }}">@csrf<button class="btn-primary btn-sm"><i data-lucide="film" class="size-4"></i>Render video</button></form>@endcan
                <a href="{{ route('projects.show', [$project, 'tab' => 'delivery']) }}" class="btn-secondary btn-sm"><i data-lucide="download" class="size-4"></i>Export</a>
            </div>
        </div>

        <div class="grid min-h-0 flex-1 grid-cols-1 lg:grid-cols-[17rem_1fr_21rem]">
            {{-- left: scenes / characters / backgrounds --}}
            <aside class="flex min-h-0 flex-col border-r border-line/60 bg-sidebar">
                <div class="flex gap-4 border-b border-line/60 px-4 pt-3 text-sm">
                    <template x-for="t in ['Scenes','Characters','Backgrounds']"><button class="tab" :class="left === t && 'active'" @click="left = t" x-text="t"></button></template>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto p-2">
                    <template x-if="left === 'Scenes'">
                        <ul class="grid gap-1" x-ref="list">
                            <template x-for="s in scenes" :key="s.id">
                                <li><button @click="select(s.id)" class="flex w-full gap-2 rounded-lg p-2 text-left transition hover:bg-elevated" :class="scene?.id === s.id && 'bg-primary/15 ring-1 ring-primary/50'">
                                    <div class="aspect-[9/16] w-10 shrink-0 overflow-hidden rounded bg-elevated"><img x-show="s.preview" :src="s.preview" class="size-full object-cover" alt=""></div>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-1.5 text-xs"><span class="font-mono" x-text="'#' + s.sequence"></span><span class="size-1.5 rounded-full" :class="dot(s.status)"></span><span class="text-muted" x-text="s.status.replace('_',' ')"></span></div>
                                        <div class="line-clamp-2 text-xs text-muted" x-text="s.kind === 'end_card' ? 'End card' : (s.narration || s.plan.visual_description || '')"></div>
                                    </div>
                                </button></li>
                            </template>
                        </ul>
                    </template>
                    <template x-if="left === 'Characters'">
                        <div class="grid gap-1 p-1 text-sm"><p class="px-1 text-xs text-muted">Click to add to the selected scene (max 3).</p>
                            <template x-for="c in options.characters"><button class="rounded-lg px-2 py-1.5 text-left hover:bg-elevated" @click="addCharacter(c)" :disabled="!editable()" x-text="c"></button></template>
                            <a href="{{ route('characters.index') }}" class="mt-2 px-2 text-xs text-indigo-300 hover:underline">Manage character library</a></div>
                    </template>
                    <template x-if="left === 'Backgrounds'">
                        <div class="grid gap-1 p-1 text-sm"><template x-for="b in options.backgrounds"><button class="flex items-center justify-between rounded-lg px-2 py-1.5 text-left hover:bg-elevated" :class="scene?.plan.background === b && 'bg-primary/15'" @click="setPlan({background: b})" :disabled="!editable()"><span x-text="b"></span><i x-show="scene?.plan.background === b" data-lucide="check" class="size-4"></i></button></template></div>
                    </template>
                </div>
            </aside>

            {{-- canvas --}}
            <section class="flex min-h-0 flex-col items-center justify-center gap-3 bg-[#070a14] p-4">
                <template x-if="scene && scene.source === 'ai_video'">
                    <div class="flex h-full max-h-full flex-col items-center gap-2">
                        <template x-if="approvedClip()"><video :src="approvedClip().url" controls class="h-full max-h-[calc(100vh-22rem)] rounded-lg"></video></template>
                        <template x-if="!approvedClip()"><div class="grid aspect-[9/16] h-full max-h-[calc(100vh-22rem)] place-items-center rounded-lg border border-dashed border-line p-6 text-center text-sm text-muted">No approved clip yet. Generate one, review it in the panel, and approve it.</div></template>
                    </div>
                </template>
                <template x-if="scene && scene.source !== 'ai_video'">
                    <div class="relative aspect-[9/16] h-full max-h-[calc(100vh-22rem)] overflow-hidden rounded-lg border border-line bg-black">
                        <iframe x-ref="player" :src="playerUrl" sandbox="allow-scripts" class="size-full" title="Sandboxed scene preview" @load="playerReady = false"></iframe>
                        <div x-show="!scene.validation?.ok" class="absolute inset-0 grid place-items-center bg-black/70 p-6 text-center text-sm text-muted">This scene has no validated animation yet. Generate it from the panel.</div>
                    </div>
                </template>
                <input x-show="scene && scene.source !== 'ai_video'" type="range" min="0" :max="sceneDuration()" step="0.033" x-model.number="time" @input="seek(time)" class="w-full max-w-md accent-primary" aria-label="Scrub scene">
                <p class="text-xs text-red-300" x-show="playerError" x-text="playerError"></p>
            </section>

            {{-- properties --}}
            <aside class="min-h-0 overflow-y-auto border-l border-line/60 bg-sidebar p-4">
                <template x-if="!scene"><p class="text-sm text-muted">Select a scene.</p></template>
                <template x-if="scene">
                    <div class="grid gap-5 text-sm">
                        <div>
                            <div class="flex items-center gap-2"><h2 x-text="'Scene ' + scene.sequence"></h2><span class="badge" :class="toneFor(scene.status)" x-text="scene.status.replace('_',' ')"></span></div>
                            <p class="mt-1 font-mono text-xs text-muted" x-text="scene.start.toFixed(2) + '–' + scene.end.toFixed(2) + ' s (' + (scene.end - scene.start).toFixed(2) + ' s)'"></p>
                            <p class="mt-2 rounded-lg bg-red-500/10 p-2 text-xs text-red-200" x-show="scene.error" x-text="scene.error"></p>
                        </div>

                        <div x-show="scene.kind === 'story' && scene.narration">
                            <div class="label flex items-center gap-1"><i data-lucide="lock" class="size-3"></i>Locked narration</div>
                            <p class="rounded-lg bg-bg/60 p-2 text-xs" x-text="scene.narration"></p>
                        </div>

                        <template x-if="scene.kind === 'end_card'">
                            <div class="grid gap-2"><div class="label">End card advice (6–12 words, true to the story)</div>
                                <textarea class="input" rows="2" :value="scene.plan.end_card?.advice" @change="setPlan({end_card: {advice: $event.target.value}})" :disabled="!editable()"></textarea>
                                <input class="input" placeholder="Accent word" :value="scene.plan.end_card?.accent" @change="setPlan({end_card: {accent: $event.target.value}})" :disabled="!editable()"></div>
                        </template>

                        <div x-show="scene.kind === 'story'"><div class="label" x-text="scene.source === 'ai_video' ? 'Video prompt' : 'Scene direction'"></div>
                            <textarea class="input" rows="3" :value="scene.source === 'ai_video' ? (scene.plan.video_prompt || scene.plan.visual_description) : scene.plan.visual_description" @change="setPlan(scene.source === 'ai_video' ? {video_prompt: $event.target.value} : {visual_description: $event.target.value})" :disabled="!editable()"></textarea></div>

                        <template x-if="scene.kind === 'story' && scene.source !== 'ai_video'">
                            <div class="grid gap-3">
                                <div><div class="label">Characters</div>
                                    <div class="flex flex-wrap gap-1"><template x-for="(c, i) in scene.plan.characters || []"><span class="badge tone-primary">
                                        <span x-text="c"></span><button x-show="editable() && c !== 'Kiki'" @click="removeCharacter(i)" aria-label="Remove">×</button></span></template></div></div>
                                <div><div class="label">Expression</div><select class="input" :value="scene.plan.emotion" @change="setPlan({emotion: $event.target.value})" :disabled="!editable()"><template x-for="o in options.emotions"><option :value="o" x-text="o" :selected="o === scene.plan.emotion"></option></template></select></div>
                                <div><div class="label">Actions (one per line)</div><textarea class="input" rows="2" :value="(scene.plan.character_actions || []).join('\n')" @change="setPlan({character_actions: $event.target.value.split('\n').map(s => s.trim()).filter(Boolean).slice(0,4)})" :disabled="!editable()"></textarea></div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div><div class="label">Shot</div><select class="input" @change="setPlan({camera: {shot: $event.target.value}})" :disabled="!editable()"><template x-for="o in options.shots"><option :value="o" x-text="o.replaceAll('_',' ')" :selected="o === scene.plan.camera?.shot"></option></template></select></div>
                                    <div><div class="label">Movement</div><select class="input" @change="setPlan({camera: {movement: $event.target.value}})" :disabled="!editable()"><template x-for="o in options.moves"><option :value="o" x-text="o.replaceAll('_',' ')" :selected="o === scene.plan.camera?.movement"></option></template></select></div>
                                    <div><div class="label">Lighting</div><select class="input" @change="setPlan({lighting: $event.target.value})" :disabled="!editable()"><template x-for="o in options.looks"><option :value="o" x-text="o" :selected="o === scene.plan.lighting"></option></template></select></div>
                                    <div><div class="label">Transition in</div><select class="input" @change="setPlan({transition: $event.target.value})" :disabled="!editable() || scene.sequence === 1"><template x-for="o in options.transitions"><option :value="o" x-text="o" :selected="o === scene.plan.transition"></option></template></select></div>
                                </div>
                            </div>
                        </template>

                        <div class="grid gap-2" x-show="scene.kind === 'story'">
                            <div class="label">Sound</div>
                            <select class="input" @change="setPlan({music: $event.target.value})" :disabled="!editable()" aria-label="Music mood"><template x-for="o in options.music"><option :value="o" x-text="'Music: ' + o.replaceAll('_',' ')" :selected="o === scene.plan.music"></option></template></select>
                            <div class="flex flex-wrap gap-1"><template x-for="fx in options.sfx"><button class="badge" :class="(scene.plan.sound_effects || []).includes(fx) ? 'tone-accent' : 'tone-muted'" @click="toggleSfx(fx)" :disabled="!editable()" x-text="fx.replaceAll('_',' ')"></button></template></div>
                        </div>

                        <div class="grid gap-2 rounded-lg border border-line p-3" x-show="canEdit">
                            <div class="label">Generate</div>
                            <template x-if="project.mode === 'hybrid' && scene.kind === 'story'">
                                <div class="flex gap-1"><template x-for="src in ['canvas','ai_video']"><button class="btn-sm flex-1" :class="scene.source === src ? 'btn-primary' : 'btn-secondary'" @click="setSource(src)" x-text="src === 'canvas' ? 'Canvas' : 'AI video'"></button></template></div>
                            </template>
                            <select class="input" x-model="scene.credential" @change="saveModel()" aria-label="AI connection for this scene"><option value="">Project default model</option><template x-for="c in options.credentials"><option :value="c.id" x-text="c.name + (c.usable ? '' : ' (not connected)')"></option></template></select>
                            <input class="input" placeholder="Model id (optional)" x-model="scene.model" @change="saveModel()">
                            <textarea class="input" rows="2" x-model="direction" placeholder="Note for this regeneration (optional)"></textarea>
                            <div class="flex flex-wrap gap-2">
                                <button class="btn-primary btn-sm" @click="regenerate()" x-text="scene.source === 'ai_video' ? 'Generate clip' : (project.strategy === 'template' ? 'Re-stage scene' : 'Regenerate with AI')"></button>
                                <button class="btn-secondary btn-sm" x-show="scene.source !== 'ai_video' && project.strategy === 'ai'" @click="regenerate('template')">Use template</button>
                            </div>
                            <label class="flex items-center gap-2 text-xs text-muted"><input type="checkbox" x-model="approveCost" class="accent-primary">Approve costs above the threshold</label>
                        </div>

                        <template x-if="scene.source === 'ai_video'">
                            <div class="grid gap-2"><div class="label">Clips</div>
                                <template x-for="c in scene.clips" :key="c.id"><div class="rounded-lg border border-line p-2">
                                    <div class="flex items-center justify-between text-xs"><span x-text="c.origin"></span><span class="badge" :class="toneFor(c.status)" x-text="c.status"></span></div>
                                    <video x-show="c.url" :src="c.url" controls class="mt-2 w-full rounded"></video>
                                    <div class="mt-2 flex gap-2" x-show="c.status === 'ready' || c.status === 'approved'"><button class="btn-primary btn-sm" @click="clip(c.id, 'approve')" x-show="c.status !== 'approved'">Approve</button><button class="btn-ghost btn-sm" @click="clip(c.id, 'reject')">Reject</button></div>
                                </div></template>
                                <p x-show="!scene.clips.length" class="text-xs text-muted">No clips yet.</p>
                            </div>
                        </template>

                        <div x-show="scene.kind === 'story' && scene.cue_end !== null && nextStory()">
                            <div class="label">Timing (snap to subtitle cues)</div>
                            <div class="flex items-center gap-2"><button class="btn-secondary btn-sm" @click="moveBoundary(-1)" :disabled="!editable() || scene.cue_end <= scene.cue_start">− cue</button><span class="flex-1 text-center font-mono text-xs" x-text="'ends after cue ' + (scene.cue_end + 1)"></span><button class="btn-secondary btn-sm" @click="moveBoundary(1)" :disabled="!editable() || scene.cue_end + 1 >= nextStory()?.cue_end">+ cue</button></div>
                            <p class="hint">Boundaries move between subtitle cues so the narration stays intact.</p>
                        </div>

                        <div><div class="label">Versions</div>
                            <ul class="grid max-h-48 gap-1 overflow-y-auto"><template x-for="v in scene.versions" :key="v.id"><li><button class="flex w-full items-center gap-2 rounded px-2 py-1 text-left text-xs hover:bg-elevated" :class="v.id === scene.current && 'bg-primary/15'" @click="switchVersion(v.id)" :disabled="!canEdit">
                                <span class="font-mono" x-text="'v' + v.version"></span><span class="badge tone-muted" x-text="v.origin"></span><span class="size-1.5 rounded-full" :class="v.valid ? 'bg-success' : (v.has_code ? 'bg-error' : 'bg-muted')"></span><span class="truncate text-muted" x-text="v.notes || v.at"></span></button></li></template></ul></div>

                        <details x-show="scene.source !== 'ai_video'" class="rounded-lg border border-line p-2">
                            <summary class="cursor-pointer text-xs text-muted">Animation code (advanced)</summary>
                            <textarea class="input mt-2 font-mono text-[11px]" rows="12" x-model="code" spellcheck="false" :disabled="!canEdit"></textarea>
                            <div class="mt-2 flex items-center gap-2"><button class="btn-secondary btn-sm" @click="saveCode()" :disabled="!canEdit">Validate & save as new version</button></div>
                            <ul class="mt-2 text-[11px] text-red-300"><template x-for="e in (scene.validation?.errors || [])"><li x-text="e"></li></template></ul>
                        </details>
                    </div>
                </template>
            </aside>
        </div>

        {{-- timeline --}}
        <div class="border-t border-line/60 bg-sidebar px-4 py-3">
            <div class="grid grid-cols-[5.5rem_1fr] items-center gap-x-3 gap-y-1.5 text-[11px]">
                <div class="text-muted">Scenes</div>
                <div class="relative h-9 overflow-hidden rounded bg-bg" x-ref="sceneTrack">
                    <template x-for="s in scenes" :key="s.id"><button @click="select(s.id)" :data-id="s.id" class="absolute top-0 h-full border-r border-bg px-1 text-left font-mono transition" :class="scene?.id === s.id ? 'bg-primary/60' : (s.status === 'failed' ? 'bg-error/40' : 'bg-elevated hover:bg-primary/30')" :style="`left:${pct(s.start)}%;width:${pct(s.end - s.start)}%`" :title="'Scene ' + s.sequence"><span x-text="s.sequence"></span></button></template>
                    <div class="pointer-events-none absolute top-0 h-full w-px bg-accent" :style="`left:${pct((scene?.start || 0) + time)}%`"></div>
                </div>
                <div class="text-muted">Voiceover</div><div class="h-10 overflow-hidden rounded bg-bg" x-ref="wave"><p x-show="!project.voice" class="p-2 text-muted">No voice track</p></div>
                <div class="text-muted">Music</div>
                <div class="relative h-5 rounded bg-bg"><template x-for="s in scenes"><div class="absolute top-0 h-full truncate border-r border-bg px-1 text-[10px] leading-5 text-indigo-200/80" :class="musicTone(s.plan.music)" :style="`left:${pct(s.start)}%;width:${pct(s.end - s.start)}%`" x-text="(s.plan.music || '').replaceAll('_',' ')"></div></template></div>
                <div class="text-muted">Sound FX</div>
                <div class="relative h-5 rounded bg-bg"><template x-for="s in scenes"><template x-for="(fx, i) in (s.plan.sound_effects || [])"><span class="absolute top-0.5 size-4 rounded-full bg-accent/70" :style="`left:calc(${pct(s.start + 0.05 + i * 0.6)}% - 2px)`" :title="'#' + s.sequence + ' ' + fx"></span></template></template></div>
                <div class="text-muted">Subtitles</div>
                <div class="relative h-5 overflow-hidden rounded bg-bg"><template x-for="c in cues"><div class="absolute top-0 h-full truncate border-r border-bg bg-white/10 px-0.5 text-[9px] leading-5" :style="`left:${pct(c.start)}%;width:${pct(c.end - c.start)}%`" :title="c.text" x-text="c.text"></div></template></div>
            </div>
        </div>
    </div>

    <script>
    function sceneEditor(cfg) {
        return {
            ...cfg, project: {}, scenes: [], cues: [], options: { characters: [], backgrounds: [], emotions: [], shots: [], moves: [], looks: [], transitions: [], sfx: [], music: [], credentials: [] },
            scene: null, left: 'Scenes', time: 0, playing: false, playerUrl: '', playerError: '', busy: '', code: '', direction: '', approveCost: false,
            history: {}, future: {}, wave: null, poll: null,
            async load(keepTime) {
                const data = await mfFetch(this.state);
                Object.assign(this, { project: data.project, scenes: data.scenes, cues: data.cues, options: data.options });
                const id = this.scene?.id || this.initial || this.scenes[0]?.id;
                if (id) this.select(id, true);
                this.$nextTick(() => { window.mfIcons(); this.initWave(); this.initSortable(); });
                const active = this.scenes.some(s => s.status === 'generating');
                clearTimeout(this.poll); if (active) this.poll = setTimeout(() => this.load(true), 4000);
            },
            select(id, keep) {
                const prev = this.scene?.id; this.scene = this.scenes.find(s => s.id === id) || null; if (!this.scene) return;
                this.code = this.scene.code || '';
                if (!keep || prev !== id || !this.playerUrl.includes(this.scene.current)) { this.playerUrl = this.scene.player + '?version=' + this.scene.current; this.time = 0; this.playing = false; this.playerError = ''; }
                history.replaceState(null, '', '?scene=' + id);
            },
            sceneDuration() { return this.scene ? +(this.scene.end - this.scene.start).toFixed(3) : 0; },
            pct(v) { return this.project.duration ? (v / this.project.duration * 100) : 0; },
            fmt(s) { s = Math.max(0, s || 0); return Math.floor(s / 60) + ':' + String(Math.floor(s % 60)).padStart(2, '0') + '.' + String(Math.floor((s % 1) * 10)); },
            editable() { return this.canEdit && this.scene && !this.busy; },
            dot(st) { return { failed: 'bg-error', preview_ready: 'bg-success', rendered: 'bg-success', generated: 'bg-success', generating: 'bg-accent animate-pulse' }[st] || 'bg-muted'; },
            toneFor(st) { return { failed: 'tone-error', rejected: 'tone-error', preview_ready: 'tone-success', rendered: 'tone-success', generated: 'tone-success', approved: 'tone-success', ready: 'tone-success', generating: 'tone-accent', pending: 'tone-warning' }[st] || 'tone-muted'; },
            musicTone(m) { return { dark_drone: 'bg-indigo-900/40', tense_strings: 'bg-red-900/30', sparse_piano: 'bg-sky-900/30', pulse_synth: 'bg-purple-900/40', epic_swell: 'bg-amber-900/30' }[m] || ''; },
            nextStory() { return this.scenes.find(s => s.sequence === this.scene.sequence + 1 && s.kind === 'story'); },
            approvedClip() { return (this.scene?.clips || []).find(c => c.status === 'approved'); },
            sceneRenderable() { return this.scene && (this.scene.source === 'ai_video' ? !!this.approvedClip() : !!this.scene.validation?.ok); },
            // player (sandboxed iframe, postMessage only)
            post(m) { this.$refs.player?.contentWindow?.postMessage(m, '*'); },
            togglePlay() { this.playing = !this.playing; this.post({ cmd: this.playing ? 'play' : 'pause' }); },
            seek(t) { this.post({ cmd: 'seek', t }); },
            init() {
                window.addEventListener('message', (e) => {
                    if (!e.data || e.data.source !== 'mf-player' || e.source !== this.$refs.player?.contentWindow) return;
                    if (e.data.type === 'time' && this.playing) this.time = +e.data.t.toFixed(2);
                    if (e.data.type === 'error') this.playerError = e.data.message;
                });
            },
            keys(e) {
                if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return;
                if (e.code === 'Space') { e.preventDefault(); this.togglePlay(); }
                if ((e.ctrlKey || e.metaKey) && e.key === 'z') { e.preventDefault(); this.undo(); }
                if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.shiftKey && e.key === 'Z'))) { e.preventDefault(); this.redo(); }
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { const i = this.scenes.indexOf(this.scene) + (e.key === 'ArrowDown' ? 1 : -1); if (this.scenes[i]) { e.preventDefault(); this.select(this.scenes[i].id); } }
            },
            // edits → new versions on the server; undo/redo moves between versions
            async act(label, fn, reload = true) {
                this.busy = label;
                try { const r = await fn(); if (r?.message) toast(r.message); if (reload) await this.load(true); return r; }
                catch (e) { toast(e.message, 'error'); }
                finally { this.busy = ''; this.$nextTick(() => window.mfIcons()); }
            },
            pushHistory() { (this.history[this.scene.id] ||= []).push(this.scene.current); this.future[this.scene.id] = []; },
            async setPlan(changes) {
                if (!this.editable()) return; this.pushHistory();
                await this.act('Saving…', () => mfFetch(`${this.base}/scenes/${this.scene.id}`, { method: 'PATCH', body: { plan: changes } }));
                if (this.project.strategy === 'ai' && this.scene.kind === 'story' && this.scene.source === 'canvas') toast('Saved as a new version. Regenerate to apply direction changes to the animation.');
            },
            addCharacter(c) { const list = [...(this.scene.plan.characters || [])]; if (list.includes(c) || list.length >= 3) return; this.setPlan({ characters: [...list, c] }); },
            removeCharacter(i) { const list = [...this.scene.plan.characters]; list.splice(i, 1); this.setPlan({ characters: list }); },
            toggleSfx(fx) { let l = [...(this.scene.plan.sound_effects || [])]; l = l.includes(fx) ? l.filter(x => x !== fx) : [...l, fx].slice(0, 4); this.setPlan({ sound_effects: l }); },
            saveModel() { this.act('Saving…', () => mfFetch(`${this.base}/scenes/${this.scene.id}`, { method: 'PATCH', body: { ai_credential_id: this.scene.credential || null, ai_model: this.scene.model || null } }), false); },
            regenerate(strategy) {
                const body = { direction: this.direction || null, approved: this.approveCost, strategy: strategy || null };
                this.act('Queued…', () => mfFetch(`${this.base}/scenes/${this.scene.id}/regenerate`, { method: 'POST', body })).then(() => { this.direction = ''; toast('Generation queued for scene ' + this.scene.sequence); this.scene.status = 'generating'; setTimeout(() => this.load(true), 3000); });
            },
            renderScene() { this.act('Queued…', () => mfFetch(`${this.base}/scenes/${this.scene.id}/render`, { method: 'POST' }), false).then(r => r && toast('Scene render queued. Unchanged renders are reused.', 'success')); },
            setSource(src) { this.act('Saving…', () => mfFetch(`${this.base}/scenes/${this.scene.id}/source`, { method: 'POST', body: { source: src } })); },
            clip(id, decision) { this.act('Saving…', () => mfFetch(`${this.base}/scenes/${this.scene.id}/clips/${id}`, { method: 'POST', body: { decision } })); },
            moveBoundary(d) { this.act('Retiming…', () => mfFetch(`${this.base}/scenes/${this.scene.id}/timing`, { method: 'POST', body: { cue_end: this.scene.cue_end + d } })); },
            saveCode() { this.pushHistory(); this.act('Validating…', () => mfFetch(`${this.base}/scenes/${this.scene.id}/code`, { method: 'POST', body: { code: this.code } })).then(() => setTimeout(() => this.load(true), 3500)); },
            switchVersion(vid, record = true) { if (vid === this.scene.current) return; if (record) this.pushHistory(); return this.act('Switching version…', () => mfFetch(`${this.base}/scenes/${this.scene.id}/revert`, { method: 'POST', body: { version_id: vid } })); },
            canUndo() { return !!this.scene && (this.history[this.scene.id] || []).length > 0; },
            canRedo() { return !!this.scene && (this.future[this.scene.id] || []).length > 0; },
            async undo() { if (!this.canUndo()) return; const id = this.scene.id; const prev = this.history[id].pop(); (this.future[id] ||= []).push(this.scene.current); await this.switchVersion(prev, false); },
            async redo() { if (!this.canRedo()) return; const id = this.scene.id; const next = this.future[id].pop(); (this.history[id] ||= []).push(this.scene.current); await this.switchVersion(next, false); },
            initWave() {
                if (this.wave || !this.project.voice || !window.WaveSurfer) return;
                this.wave = WaveSurfer.create({ container: this.$refs.wave, height: 40, waveColor: '#475569', progressColor: '#6366F1', cursorColor: '#06B6D4', barWidth: 2, barGap: 1, url: this.project.voice, interact: true });
                this.wave.on('interaction', (t) => { const s = this.scenes.find(s => t >= s.start && t < s.end); if (s) { this.select(s.id); this.time = +(t - s.start).toFixed(2); this.seek(this.time); } });
            },
            initSortable() {
                if (!this.canEdit || this._sortable || !window.Sortable || !this.$refs.list) return;
                this._sortable = Sortable.create(this.$refs.list, { animation: 150, onEnd: async (ev) => {
                    // put the DOM node back; Alpine re-renders from server state after validation
                    const ref = ev.from.children[ev.oldIndex > ev.newIndex ? ev.oldIndex + 1 : ev.oldIndex] || null;
                    ev.from.insertBefore(ev.item, ref);
                    const order = this.scenes.map(s => s.id); const [m] = order.splice(ev.oldIndex, 1); order.splice(ev.newIndex, 0, m);
                    try { const r = await mfFetch(`${this.base}/scenes/order`, { method: 'POST', body: { order } }); if (r.message) toast(r.message); }
                    catch (e) { toast(e.message, 'error'); }
                    await this.load(true);
                }});
            },
        };
    }
    </script>
</x-layouts.app>

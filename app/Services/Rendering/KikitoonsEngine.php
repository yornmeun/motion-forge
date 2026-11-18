<?php

namespace App\Services\Rendering;

use App\Contracts\AnimationEngineInterface;
use App\Models\Project;
use App\Models\Scene;
use App\Services\Production\SrtParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Adapter around the Kikitoons engine. Uses the original engine from KIKITOONS_ENGINE_PATH when it
 * is present and complete, otherwise the bundled Kikitoons-compatible engine. Every execution of
 * scene code happens in engine/renderer/render.mjs (headless Chrome, CSP, no network, timeouts),
 * started with a scrubbed environment.
 */
class KikitoonsEngine implements AnimationEngineInterface
{
    public function __construct(private StoryAssembler $assembler, private SrtParser $srt) {}

    public function engineDir(): string
    {
        $custom = config('motionforge.engine_path');
        if ($custom && is_dir($custom) && collect(['core.js', 'bg.js', 'engine.js'])->every(fn ($f) => is_file($custom.'/'.$f))) {
            return realpath($custom);
        }

        return config('motionforge.compat_engine_path');
    }

    public function engineInfo(): array
    {
        $dir = $this->engineDir();

        return [
            'dir' => $dir,
            'kind' => $dir === config('motionforge.compat_engine_path') ? 'MotionForge Kikitoons-compatible engine' : 'Original Kikitoons engine',
            'hash' => $this->engineHash(),
        ];
    }

    public function engineHash(): string
    {
        $dir = $this->engineDir();

        return substr(hash('sha256', collect(['core.js', 'bg.js', 'engine.js'])->map(fn ($f) => (string) @file_get_contents($dir.'/'.$f))->implode('|').@file_get_contents(base_path('engine/kikitoons-compat/director.js')).@file_get_contents(config('motionforge.renderer'))), 0, 16);
    }

    public function validateCode(string $code): array
    {
        $p = SandboxProcess::make([config('motionforge.node'), config('motionforge.validator')], $this->tmp('validate'), 30, json_encode(['code' => $code, 'allow' => ['mfStage', 'SCENE_INDEX']]));
        $p->run();
        $out = json_decode(trim($p->getOutput()), true);
        if (! is_array($out)) {
            throw new RuntimeException('The code validator failed to run: '.mb_substr($p->getErrorOutput(), 0, 300));
        }

        return $out;
    }

    /** Render one frame from the middle of the scene with candidate code; reports runtime errors. */
    public function probeCode(Scene $scene, string $code): array
    {
        $version = clone ($scene->currentVersion ?? new \App\Models\SceneVersion(['plan' => []]));
        $version->code = $code;
        $copy = clone $scene;
        $copy->setRelation('currentVersion', $version);
        $work = $this->tmp('probe');
        try {
            $res = $this->run($this->writeStory(collect([$copy]), $scene->project, $work), ['mode' => 'probe'], $work, 120);
        } finally {
            $this->cleanup($work);
        }

        return $res['ok'] ? ['ok' => true, 'message' => null] : ['ok' => false, 'message' => $res['message']];
    }

    public function probeProject(Project $project): array
    {
        $scenes = $project->scenes()->with('currentVersion')->get();
        $work = $this->tmp('probe');
        try {
            $res = $this->run($this->writeStory($scenes, $project, $work, 0), ['mode' => 'probe'], $work, 600);
        } finally {
            $this->cleanup($work);
        }

        return ['ok' => $res['ok'], 'scene' => $res['scene'] ?? null, 'message' => $res['message'] ?? null];
    }

    public function generatePreview(Scene $scene, ?float $at = null): string
    {
        $scene->loadMissing('currentVersion', 'project');
        $at ??= min($scene->duration() - 0.05, max(0.2, $scene->duration() * 0.55));
        $work = $this->tmp('preview');
        $out = $work.'/still.png';
        try {
            $res = $this->run($this->writeStory(collect([$scene]), $scene->project, $work), ['mode' => 'still', 'at' => $at, 'out' => $out], $work, 120);
            if (! $res['ok']) {
                throw new RuntimeException($res['message']);
            }
            $rel = $scene->project->storageDir('previews/'.$scene->id.'-'.$scene->current_version_id.'.png');
            Storage::disk(config('motionforge.disk'))->put($rel, file_get_contents($out));

            return $rel;
        } finally {
            $this->cleanup($work);
        }
    }

    public function renderScene(Scene $scene, ?callable $onProgress = null): string
    {
        $project = $scene->project;
        $scenes = $project->scenes()->with('currentVersion')->get();
        $rel = $project->storageDir('renders/scenes/'.$scene->id.'-'.$this->segmentHash($scenes, $scene).'.mp4');
        $disk = Storage::disk(config('motionforge.disk'));
        if ($disk->exists($rel)) {
            return $rel; // unchanged scene: reuse the completed render
        }
        $work = $this->tmp('scene');
        try {
            $out = $work.'/scene.mp4';
            $res = $this->run($this->writeStory($scenes, $project, $work, 0), [
                'mode' => 'video', 'from' => $scene->start_time, 'to' => $scene->end_time, 'out' => $out,
                'fps' => $project->frame_rate, 'preset' => 'medium', 'crf' => 19,
            ], $work, (int) config('motionforge.scene_timeout_sec', 900), $onProgress);
            if (! $res['ok']) {
                throw new SceneRenderException($res['message'], $res['scene'] ?? null);
            }
            $disk->put($rel, fopen($out, 'rb'));

            return $rel;
        } finally {
            $this->cleanup($work);
        }
    }

    /** Render a single-scene story to a still (used for thumbnails). */
    public function renderStill(string $storyJs, float $at, string $outAbs, ?string $capsJs = null): void
    {
        $work = $this->tmp('still');
        try {
            file_put_contents($work.'/story.js', $storyJs);
            if ($capsJs) {
                file_put_contents($work.'/caps.js', $capsJs);
            }
            $res = $this->run(['story' => $work.'/story.js', 'caps' => $capsJs ? $work.'/caps.js' : null], ['mode' => 'still', 'at' => $at, 'out' => $work.'/still.png'], $work, 120);
            if (! $res['ok']) {
                throw new RuntimeException($res['message']);
            }
            copy($work.'/still.png', $outAbs);
        } finally {
            $this->cleanup($work);
        }
    }

    /** Cache key for a scene segment: changes only when something visible in that segment changes. */
    public function segmentHash(Collection $scenes, Scene $scene): string
    {
        $scenes = $scenes->values();
        $i = $scenes->search(fn ($s) => $s->id === $scene->id);
        $parts = [$this->engineHash(), $scene->start_time, $scene->end_time, $scene->currentVersion?->code, json_encode($scene->plan()), $scene->project->frame_rate];
        $prev = $scenes[$i - 1] ?? null;
        $next = $scenes[$i + 1] ?? null;
        if ($prev && ($scene->plan()['transition'] ?? 'none') !== 'none') $parts[] = $prev->currentVersion?->code;
        if ($next && ($next->plan()['transition'] ?? 'none') !== 'none') $parts[] = $next->currentVersion?->code.$next->start_time;
        $package = $scene->project->approvedPackage;
        if ($package) {
            $parts[] = $package->srt_hash;
            $parts[] = json_encode($scenes->map(fn ($s) => [$s->plan()['caption_keywords'] ?? [], $s->plan()['slam_word'] ?? null]));
        }

        return substr(hash('sha256', implode('|', array_map('strval', $parts))), 0, 20);
    }

    /** Writes story.js + caps.js for the given scenes; returns file paths. */
    public function writeStory(Collection $scenes, Project $project, string $work, ?float $offset = null): array
    {
        $offset ??= (float) ($scenes->first()?->start_time ?? 0);
        $hybridCaptions = $project->production_mode->value !== 'kikitoons';
        file_put_contents($work.'/story.js', $this->assembler->story($scenes, [
            'offset' => $offset, 'fps' => $project->frame_rate, 'no_captions' => $hybridCaptions,
            'caption_y' => $project->setting('subtitles.y', 1344),
        ]));
        $caps = null;
        $package = $project->approvedPackage;
        if ($package && $package->srt_text) {
            $all = $project->scenes()->with('currentVersion')->get();
            file_put_contents($work.'/caps.js', $this->assembler->caps($this->srt->parse($package->srt_text), $all, $offset));
            $caps = $work.'/caps.js';
        }

        return ['story' => $work.'/story.js', 'caps' => $caps];
    }

    /**
     * Run render.mjs. @return array{ok: bool, message?: string, scene?: int, done?: array}
     */
    public function run(array $files, array $job, string $work, int $timeout, ?callable $onProgress = null): array
    {
        $job = array_merge([
            'engineDir' => $this->engineDir(), 'storyFile' => $files['story'], 'capsFile' => $files['caps'] ?? null,
            'fontFile' => config('motionforge.font'), 'ffmpeg' => config('motionforge.ffmpeg'), 'chrome' => config('motionforge.chrome'),
            'frameTimeoutMs' => (int) config('motionforge.frame_timeout_ms', 8000), 'totalTimeoutSec' => $timeout,
        ], $job);
        file_put_contents($work.'/job.json', json_encode($job));
        $p = SandboxProcess::make([config('motionforge.node'), config('motionforge.renderer'), $work.'/job.json'], $work, $timeout + 30);
        $buffer = '';
        $last = null;
        $p->run(function ($type, $data) use (&$buffer, &$last, $onProgress) {
            if ($type !== 'out') {
                return;
            }
            $buffer .= $data;
            while (($nl = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $nl);
                $buffer = substr($buffer, $nl + 1);
                $msg = json_decode($line, true);
                if (! is_array($msg)) continue;
                $last = $msg;
                if (($msg['type'] ?? '') === 'progress' && $onProgress) {
                    $onProgress((int) $msg['frame'], (int) $msg['total']);
                }
            }
        });
        if ($buffer !== '' && is_array($m = json_decode(trim($buffer), true))) {
            $last = $m;
        }
        if (($last['type'] ?? '') === 'done' && $p->isSuccessful()) {
            return ['ok' => true, 'done' => $last];
        }
        $message = $last['message'] ?? trim(mb_substr($p->getErrorOutput(), -600)) ?: 'The renderer exited unexpectedly.';

        return ['ok' => false, 'message' => mb_substr($message, 0, 1000), 'scene' => $last['scene'] ?? null];
    }

    public function tmp(string $kind): string
    {
        $dir = storage_path('app/tmp/render/'.$kind.'-'.Str::uuid());
        mkdir($dir, 0700, true);

        return $dir;
    }

    public function cleanup(string $dir): void
    {
        if (str_starts_with($dir, storage_path('app/tmp/render/'))) {
            \Illuminate\Support\Facades\File::deleteDirectory($dir);
        }
    }
}

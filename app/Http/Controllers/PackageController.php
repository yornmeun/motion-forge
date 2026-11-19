<?php

namespace App\Http\Controllers;

use App\Models\ProductionPackage;
use App\Models\Project;
use App\Services\Production\PackageService;
use App\Services\Production\ProductionWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

class PackageController extends Controller
{
    public function store(Request $request, Project $project, PackageService $packages, ProductionWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('update', $project);
        $kb = config('motionforge.uploads');
        $request->validate([
            'auto_produce' => ['nullable', 'boolean'],
            'archive' => ['nullable', 'file', 'mimes:zip', 'max:'.$kb['archive_max_kb']],
            'story' => ['nullable', 'file', 'mimes:txt,md', 'max:'.$kb['text_max_kb']],
            'narration' => ['nullable', 'file', 'mimes:txt', 'max:'.$kb['text_max_kb']],
            'srt' => ['nullable', 'file', 'max:'.$kb['text_max_kb']],
            'voiceover' => ['nullable', 'file', 'mimes:mp3,wav,m4a,aac,ogg,flac', 'max:'.$kb['audio_max_kb']],
            'characters' => ['nullable', 'array', 'max:6'], 'characters.*' => ['image', 'mimes:png,jpg,jpeg,webp', 'max:'.$kb['image_max_kb']],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        if ($request->file('srt') && strtolower($request->file('srt')->getClientOriginalExtension()) !== 'srt') {
            return back()->withErrors(['srt' => 'The subtitles must be an .srt file.']);
        }
        try {
            if ($request->file('archive')) {
                $pkg = $packages->createFromArchive($project, $request->user(), $request->file('archive'), $request->file('voiceover'));
            } else {
                // a new version starts from the previous draft/approved content; only uploaded parts change
                $base = $project->latestPackage;
                $pkg = $packages->createFromUploads($project, $request->user(), $request->only(['story', 'narration', 'srt', 'voiceover', 'characters']), $request->input('notes', $base?->notes));
                if ($base) {
                    $pkg->fill([
                        'locked_story' => $pkg->locked_story ?? $base->locked_story, 'narration_text' => $pkg->narration_text ?? $base->narration_text,
                        'srt_text' => $pkg->srt_text ?? $base->srt_text, 'voiceover_file_id' => $pkg->voiceover_file_id ?? $base->voiceover_file_id,
                        // V2 documents (scene sheet, registries, ...) carry over unless replaced
                        'documents' => array_filter(array_merge($base->documents ?? [], $pkg->documents ?? [])) ?: null,
                    ])->save();
                }
            }
        } catch (Throwable $e) {
            return back()->withErrors(['package' => 'Upload failed: '.$e->getMessage()]);
        }
        $workflow->validatePackage($pkg, $request->user(), ['auto_produce' => $request->boolean('auto_produce')]);
        $project->log('package.uploaded', "Production package v{$pkg->version} uploaded; validation queued.");

        return redirect()->route('projects.show', [$project, 'tab' => 'package'])->with('status', "Package v{$pkg->version} uploaded. Validation is running.");
    }

    public function validatePackage(Request $request, Project $project, ProductionPackage $package, ProductionWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($package->project_id === $project->id, 404);
        if ($package->isLocked()) {
            return back()->withErrors(['package' => 'This package is locked. Upload a new version to change it.']);
        }
        $workflow->validatePackage($package, $request->user(), [
            'auto_produce' => (bool) ($package->validation_report['auto_produce'] ?? false),
        ]);

        return back()->with('status', 'Validation queued.');
    }

    public function approve(Request $request, Project $project, ProductionPackage $package, PackageService $packages, ProductionWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('approve', $project);
        abort_unless($package->project_id === $project->id, 404);
        $autoProduce = (bool) ($package->validation_report['auto_produce'] ?? false);
        if ($autoProduce && ($blocker = $workflow->automaticProductionBlocker($project, $request->user()))) {
            return back()->withErrors(['package' => $blocker]);
        }
        try {
            $packages->approve($package, $request->user(), $request->boolean('acknowledge_warnings'));
        } catch (Throwable $e) {
            return back()->withErrors(['package' => $e->getMessage()]);
        }

        if ($autoProduce) {
            try {
                $workflow->startAutomaticProduction($project->fresh(), $request->user());
            } catch (Throwable $e) {
                return redirect()->route('projects.show', [$project, 'tab' => 'scenes'])
                    ->with('status', 'Package approved.')
                    ->withErrors(['production' => $e->getMessage()]);
            }

            return redirect()->route('projects.show', [$project, 'tab' => 'scenes'])
                ->with('status', 'Package approved. Storyboard, scenes and final rendering are queued automatically.');
        }

        return redirect()->route('projects.show', [$project, 'tab' => 'scenes'])->with('status', "Package v{$package->version} approved and locked. Generate the storyboard next.");
    }
}

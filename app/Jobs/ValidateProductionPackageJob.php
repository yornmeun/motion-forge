<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksGenerationJob;
use App\Models\ProductionPackage;
use App\Models\User;
use App\Services\Production\PackageService;
use App\Services\Production\PackageValidator;
use App\Services\Production\ProductionWorkflow;
use App\Services\Production\SrtTimingAligner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Gate;
use Throwable;

class ValidateProductionPackageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksGenerationJob;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(public string $packageId, public ?string $generationJobId = null, public ?int $userId = null, public array $options = []) {}

    public function handle(PackageValidator $validator, PackageService $packages, ProductionWorkflow $workflow, SrtTimingAligner $aligner): void
    {
        if (! $this->begin('Validating script, SRT and audio')) {
            return;
        }
        try {
            $package = ProductionPackage::with('project', 'voiceover')->findOrFail($this->packageId);
            if ($package->isLocked()) {
                $this->succeed(['skipped' => 'already locked']);

                return;
            }
            $package->update(['status' => 'validating']);
            $alignment = $package->voiceover
                ? $aligner->alignIfNeeded($package->srt_text, $package->voiceover->absolutePath())
                : null;
            if ($alignment) {
                $package->update(['srt_text' => $alignment['srt']]);
                $package->refresh();
            }
            $report = $validator->validate($package);
            if ($alignment) {
                $report['srt_timing_adjustment'] = [
                    'cues' => $alignment['cues'],
                    'voice_duration' => $alignment['duration'],
                    'method' => $alignment['method'],
                ];
            }
            $package->update(['status' => $report['status'], 'validation_report' => $report]);
            $package->project->log('package.validated', "Package v{$package->version} validation: {$report['status']}.");
            $this->succeed(['status' => $report['status']], $report['status'] === 'valid' ? 'Package valid' : 'Package has failed checks');

            if ($report['status'] !== 'valid' && ($this->options['auto_produce'] ?? false)) {
                $failures = collect($report['checks'])->where('status', 'fail')
                    ->map(fn (array $check) => $check['label'].': '.$check['message'])
                    ->implode(' ');
                $report['auto_produce'] = true;
                $report['auto_production_message'] = 'Video generation did not start because package validation failed. '.$failures;
                $package->update(['validation_report' => $report]);

                return;
            }

            if ($report['status'] === 'valid' && ($this->options['auto_produce'] ?? false) && $this->userId) {
                $user = User::find($this->userId);
                if (! $user || ! Gate::forUser($user)->allows('approve', $package->project)) {
                    $report['auto_produce'] = true;
                    $report['auto_production_message'] = 'A user with package approval permission must approve this package before production can start.';
                    $package->update(['validation_report' => $report]);

                    return;
                }

                $report['auto_produce'] = true;
                if ($blocker = $workflow->automaticProductionBlocker($package->project, $user)) {
                    $report['auto_production_message'] = $blocker;
                    $package->update(['validation_report' => $report]);

                    return;
                }

                $package->update(['validation_report' => $report]);
                $packages->approve($package->fresh(), $user, true);
                $workflow->startAutomaticProduction($package->project->fresh(), $user);
                $package->project->log('production.auto_started', 'Automatic storyboard, scene generation and rendering started.', [], $user);
            }
        } catch (Throwable $e) {
            ProductionPackage::whereKey($this->packageId)->where('status', 'validating')->update(['status' => 'draft']);
            $this->handleError($e);
        }
    }
}

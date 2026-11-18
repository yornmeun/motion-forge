<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksGenerationJob;
use App\Models\ProductionPackage;
use App\Services\Production\PackageValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ValidateProductionPackageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksGenerationJob;

    public int $tries = 3;
    public int $timeout = 1800;

    public function __construct(public string $packageId, public ?string $generationJobId = null) {}

    public function handle(PackageValidator $validator): void
    {
        if (! $this->begin('Validating script, SRT and audio')) return;
        try {
            $package = ProductionPackage::with('project', 'voiceover')->findOrFail($this->packageId);
            if ($package->isLocked()) {
                $this->succeed(['skipped' => 'already locked']);

                return;
            }
            $package->update(['status' => 'validating']);
            $report = $validator->validate($package);
            $package->update(['status' => $report['status'], 'validation_report' => $report]);
            $package->project->log('package.validated', "Package v{$package->version} validation: {$report['status']}.");
            $this->succeed(['status' => $report['status']], $report['status'] === 'valid' ? 'Package valid' : 'Package has failed checks');
        } catch (Throwable $e) {
            ProductionPackage::whereKey($this->packageId)->where('status', 'validating')->update(['status' => 'draft']);
            $this->handleError($e);
        }
    }
}

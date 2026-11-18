<?php

namespace App\Jobs\Concerns;

use App\Enums\JobStatus;
use App\Exceptions\AIProviderException;
use App\Exceptions\BudgetExceededException;
use App\Models\GenerationJob;
use App\Notifications\ProductionFailed;
use App\Services\Production\LockedNarrationException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared lifecycle for production jobs backed by a generation_jobs row:
 * real stage text, real counters only, cancellation, bounded exponential backoff, and
 * immediate failure (no retry) for errors a retry cannot fix.
 */
trait TracksGenerationJob
{
    public function backoff(): array
    {
        return [15, 60, 180];
    }

    protected function record(): ?GenerationJob
    {
        return $this->generationJobId ? GenerationJob::find($this->generationJobId) : null;
    }

    /** Returns false when the job was cancelled and should stop. */
    protected function begin(string $stage): bool
    {
        $r = $this->record();
        if (! $r) {
            return true;
        }
        if ($r->cancel_requested_at || $r->status === JobStatus::Cancelled) {
            $r->update(['status' => JobStatus::Cancelled, 'finished_at' => now(), 'stage' => 'Cancelled']);
            $this->releaseKey($r);
            if (method_exists($this, 'batch') && $this->batch()) {
                $this->batch()->cancel();
            }

            return false;
        }
        $r->update(['status' => JobStatus::Running, 'stage' => $stage, 'started_at' => $r->started_at ?? now(), 'attempts' => $r->attempts + 1, 'error' => null]);

        return true;
    }

    protected function stage(string $stage, ?int $current = null, ?int $total = null): void
    {
        $this->record()?->update(array_filter(['stage' => $stage, 'progress_current' => $current, 'progress_total' => $total], fn ($v) => $v !== null));
    }

    protected function succeed(array $result = [], string $stage = 'Done'): void
    {
        if ($r = $this->record()) {
            $r->update(['status' => JobStatus::Succeeded, 'stage' => $stage, 'result' => $result ?: null, 'finished_at' => now()]);
            $this->releaseKey($r);
        }
    }

    /** Decide between retrying and failing now. Always rethrows or fails the job. */
    protected function handleError(Throwable $e): void
    {
        $permanent = $e instanceof LockedNarrationException || $e instanceof BudgetExceededException
            || ($e instanceof AIProviderException && ! $e->retryable());
        $r = $this->record();
        if ($e instanceof BudgetExceededException && $r) {
            $r->update(['status' => JobStatus::WaitingApproval, 'stage' => 'Waiting for budget approval', 'error' => $e->getMessage(), 'finished_at' => now()]);
            $this->releaseKey($r);
            $this->delete();

            return;
        }
        if ($e instanceof AIProviderException && $e->kind === 'rate_limit' && $this->attempts() < $this->tries) {
            $r?->update(['stage' => 'Rate limited by the provider; retrying', 'error' => $e->getMessage()]);
            $this->release($e->retryAfter ?? 60);

            return;
        }
        if ($permanent || $this->attempts() >= $this->tries) {
            $this->fail($e);

            return;
        }
        $r?->update(['stage' => 'Retrying after error', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
        throw $e;
    }

    public function failed(?Throwable $e): void
    {
        $r = $this->record();
        if ($r && $r->status !== JobStatus::Cancelled) {
            $r->update(['status' => JobStatus::Failed, 'stage' => 'Failed', 'error' => mb_substr((string) $e?->getMessage(), 0, 2000), 'finished_at' => now()]);
            $this->releaseKey($r);
            $r->project?->log('job.failed', ucfirst(str_replace('_', ' ', $r->type)).' failed: '.mb_substr((string) $e?->getMessage(), 0, 300));
            $user = $r->project?->user;
            try {
                $user?->notify(new ProductionFailed($r));
            } catch (Throwable $n) {
                Log::warning('Failure notification not sent', ['job' => $r->id, 'error' => $n->getMessage()]);
            }
        }
        if (method_exists($this, 'onFailure')) {
            $this->onFailure($e);
        }
    }

    private function releaseKey(GenerationJob $r): void
    {
        if ($r->idempotency_key && ! str_ends_with($r->idempotency_key, ':'.$r->id)) {
            $r->update(['idempotency_key' => $r->idempotency_key.':'.$r->id]);
        }
    }
}

<?php

namespace App\Services\AI;

use App\Exceptions\BudgetExceededException;
use App\Models\AiCredential;
use App\Models\AiRequest;
use App\Models\Project;
use App\Models\User;

/**
 * Enforces project budgets, the user's monthly budget, per-credential monthly limits and
 * approval for single expensive operations. Unknown prices (null estimate) are allowed but the
 * request is still recorded; the UI shows "estimate unavailable".
 */
class BudgetGuard
{
    public function check(User $user, ?Project $project, ?AiCredential $credential, ?float $estimate, bool $approved = false): void
    {
        $estimate ??= 0.0;
        $threshold = (float) config('motionforge.ai.expensive_threshold_usd', 0.5);
        if ($estimate > $threshold && ! $approved) {
            throw new BudgetExceededException(sprintf('This operation is estimated at $%.4f, above the $%.2f approval threshold. Approve it to continue.', $estimate, $threshold), $estimate);
        }
        if ($project && $project->budget_limit_usd !== null) {
            $limit = (float) $project->budget_limit_usd + (float) $project->approved_overrun_usd;
            $spent = $project->spentUsd();
            if ($spent + $estimate > $limit) {
                throw new BudgetExceededException(sprintf('Project budget reached: $%.4f spent of $%.2f (this call ≈ $%.4f). Raise the budget or approve an overrun.', $spent, $limit, $estimate), $estimate, max(0, $limit - $spent));
            }
        }
        if ($user->monthly_budget_usd !== null) {
            $spent = $user->monthSpendUsd();
            if ($spent + $estimate > (float) $user->monthly_budget_usd) {
                throw new BudgetExceededException(sprintf('Monthly budget reached: $%.4f of $%.2f.', $spent, (float) $user->monthly_budget_usd), $estimate);
            }
        }
        if ($credential && $credential->monthly_limit_usd !== null) {
            $spent = (float) AiRequest::where('ai_credential_id', $credential->id)->where('created_at', '>=', now()->startOfMonth())->sum('estimated_cost_usd');
            if ($spent + $estimate > (float) $credential->monthly_limit_usd) {
                throw new BudgetExceededException(sprintf('The usage limit for connection "%s" is reached ($%.4f of $%.2f this month).', $credential->name, $spent, (float) $credential->monthly_limit_usd), $estimate);
            }
        }
    }

    /** Percentage of the project budget used, or null if no budget. */
    public function projectUsagePct(Project $project): ?float
    {
        if ($project->budget_limit_usd === null || (float) $project->budget_limit_usd <= 0) {
            return null;
        }

        return round($project->spentUsd() / (float) $project->budget_limit_usd * 100, 1);
    }
}

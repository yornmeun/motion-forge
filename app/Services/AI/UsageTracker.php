<?php

namespace App\Services\AI;

use App\Models\AiRequest;
use App\Models\AiUsageRecord;

class UsageTracker
{
    public function record(AiRequest $request): void
    {
        if ($request->estimated_cost_usd === null && $request->confirmed_cost_usd === null) {
            return;
        }
        AiUsageRecord::updateOrCreate(['ai_request_id' => $request->id], [
            'user_id' => $request->user_id, 'project_id' => $request->project_id,
            'provider_slug' => $request->provider->slug, 'model' => $request->model,
            'period' => $request->created_at->format('Y-m'),
            'estimated_cost_usd' => $request->estimated_cost_usd ?? 0,
            'confirmed_cost_usd' => $request->confirmed_cost_usd, 'source' => 'estimate',
        ]);
    }
}

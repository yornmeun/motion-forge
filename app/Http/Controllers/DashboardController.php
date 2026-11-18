<?php

namespace App\Http\Controllers;

use App\Enums\ProductionStatus;
use App\Models\AiRequest;
use App\Models\GenerationJob;
use App\Models\ProjectActivityLog;
use App\Models\RenderArtifact;
use App\Models\RenderJob;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $projectIds = $user->projects()->pluck('id');
        $inProduction = [ProductionStatus::Planning, ProductionStatus::Generating, ProductionStatus::Rendering, ProductionStatus::QualityControl, ProductionStatus::ReadyForReview, ProductionStatus::Planned, ProductionStatus::PackageApproved];
        $month = AiRequest::where('user_id', $user->id)->where('created_at', '>=', now()->startOfMonth());

        return view('dashboard', [
            'stats' => [
                'projects' => $projectIds->count(),
                'in_production' => $user->projects()->whereIn('status', array_map(fn ($s) => $s->value, $inProduction))->count(),
                'completed' => $user->projects()->where('status', ProductionStatus::Completed->value)->count(),
                'failed_jobs' => GenerationJob::whereIn('project_id', $projectIds)->where('status', 'failed')->where('created_at', '>=', now()->subDays(30))->count()
                    + RenderJob::whereIn('project_id', $projectIds)->where('status', 'failed')->where('created_at', '>=', now()->subDays(30))->count(),
                'ai_requests' => (clone $month)->count(),
                'tokens' => (int) (clone $month)->sum('input_tokens') + (int) (clone $month)->sum('output_tokens'),
                'estimated' => (float) (clone $month)->sum('estimated_cost_usd'),
                'unpriced' => (clone $month)->whereNull('estimated_cost_usd')->where('status', 'succeeded')->count(),
            ],
            'videos' => RenderArtifact::with('project')->whereIn('project_id', $projectIds)->where('type', 'final_video')->where('is_delivery', true)->latest()->take(4)->get(),
            'projects' => $user->projects()->latest('updated_at')->take(5)->get(),
            'activity' => ProjectActivityLog::with('project')->whereIn('project_id', $projectIds)->latest('created_at')->take(10)->get(),
            'running' => RenderJob::with('project')->whereIn('project_id', $projectIds)->whereIn('status', ['queued', 'running'])->get(),
            'hasConnection' => \App\Models\AiCredential::visibleTo($user)->where('status', 'connected')->exists(),
        ]);
    }
}

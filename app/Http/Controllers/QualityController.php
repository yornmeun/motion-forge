<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\QualityCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Human creative review. Technical checks are written only by QC jobs. */
class QualityController extends Controller
{
    public function review(Request $request, Project $project, QualityCheck $check): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($check->project_id === $project->id && $check->kind === 'creative', 404);
        $d = $request->validate(['status' => ['required', Rule::in(['pass', 'fail', 'pending'])], 'note' => ['nullable', 'string', 'max:500']]);
        $check->update(['status' => $d['status'], 'value' => $d['note'] ?? null, 'reviewed_by' => $request->user()->id]);

        return back()->with('status', 'Review saved.');
    }
}

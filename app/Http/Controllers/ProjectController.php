<?php

namespace App\Http\Controllers;

use App\Enums\AICapability;
use App\Enums\ProductionMode;
use App\Enums\ProductionStatus;
use App\Http\Requests\ProjectRequest;
use App\Models\AiCredential;
use App\Models\Project;
use App\Models\User;
use App\Services\AI\BudgetGuard;
use App\Services\AI\CostEstimator;
use App\Services\AI\ModelRegistry;
use App\Services\Production\PackageService;
use App\Services\Production\ProductionWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class ProjectController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $q = Project::query()->where(fn ($w) => $w->where('user_id', $user->id)->orWhereHas('members', fn ($m) => $m->where('users.id', $user->id)))
            ->when($request->string('q')->toString(), fn ($w, $s) => $w->where(fn ($x) => $x->where('title', 'like', "%{$s}%")->orWhere('genre', 'like', "%{$s}%")))
            ->when($request->string('status')->toString(), fn ($w, $s) => $w->where('status', $s))
            ->when($request->string('mode')->toString(), fn ($w, $m) => $w->where('production_mode', $m))
            ->latest('updated_at');

        return view('projects.index', ['projects' => $q->paginate(12)->withQueryString(), 'statuses' => ProductionStatus::cases(), 'modes' => ProductionMode::cases()]);
    }

    public function create(Request $request, ModelRegistry $registry): View
    {
        Gate::authorize('create-projects');

        return view('projects.create', $this->aiOptions($request->user(), $registry));
    }

    public function aiOptions(User $user, ModelRegistry $registry): array
    {
        $credentials = AiCredential::with('provider')->visibleTo($user)->where('is_enabled', true)->get();
        $byCap = fn (AICapability $cap) => $credentials->mapWithKeys(fn ($c) => [$c->id => $registry->modelsFor($cap, $c->provider->slug)->map(fn ($m) => ['id' => $m->model_id, 'name' => $m->display_name ?? $m->model_id, 'in' => $m->input_price_per_mtok, 'out' => $m->output_price_per_mtok, 'video' => $m->video_price_per_second, 'limits' => $m->limits])->values()]);

        return [
            'credentials' => $credentials->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'provider' => $c->provider->name, 'status' => $c->status->value, 'usable' => $c->isUsable()])->values(),
            'textModels' => $byCap(AICapability::ScenePlanning),
            'videoModels' => $byCap(AICapability::NativeVideo),
            'modes' => ProductionMode::cases(),
        ];
    }

    public function store(ProjectRequest $request, PackageService $packages, ProductionWorkflow $workflow): RedirectResponse
    {
        Gate::authorize('create-projects');
        $d = $request->validated();
        [$w, $h] = array_map('intval', explode('x', $d['resolution']));
        $settings = array_replace_recursive([
            'animation_style' => 'cinematic', 'camera' => 'mixed', 'atmosphere' => 'neutral', 'sound' => 'cinematic',
            'subtitles' => ['y' => 1344, 'style' => 'serious'], 'thumbnail' => ['title' => null],
        ], $d['settings'] ?? []);
        $project = Project::create([
            'user_id' => $request->user()->id, 'title' => $d['title'], 'description' => $d['description'] ?? null, 'genre' => $d['genre'] ?? null,
            'production_mode' => $d['production_mode'], 'workflow' => $d['workflow'], 'aspect_ratio' => $d['aspect_ratio'], 'width' => $w, 'height' => $h,
            'frame_rate' => (int) $d['frame_rate'], 'target_duration_seconds' => $d['target_duration_seconds'] ?? null,
            'generation_strategy' => $d['generation_strategy'], 'ai_credential_id' => $d['ai_credential_id'] ?? null, 'ai_model' => $d['ai_model'] ?? null,
            'video_credential_id' => $d['video_credential_id'] ?? null, 'video_model' => $d['video_model'] ?? null,
            'fallback_enabled' => (bool) ($d['fallback_enabled'] ?? false), 'fallback_credential_id' => $d['fallback_credential_id'] ?? null, 'fallback_model' => $d['fallback_model'] ?? null,
            'budget_limit_usd' => $d['budget_limit_usd'] ?? null, 'settings' => $settings, 'status' => ProductionStatus::Draft,
        ]);
        $project->log('project.created', "Project created ({$project->production_mode->label()}).");
        $tab = 'package';
        try {
            if ($request->file('archive')) {
                $pkg = $packages->createFromArchive($project, $request->user(), $request->file('archive'));
                $workflow->validatePackage($pkg, $request->user());
            } elseif ($request->hasAny(['story', 'narration', 'srt', 'voiceover']) && collect(['story', 'narration', 'srt', 'voiceover'])->contains(fn ($f) => $request->file($f))) {
                $pkg = $packages->createFromUploads($project, $request->user(), $request->only(['story', 'narration', 'srt', 'voiceover', 'characters']));
                $workflow->validatePackage($pkg, $request->user());
            }
        } catch (Throwable $e) {
            return redirect()->route('projects.show', [$project, 'tab' => $tab])->withErrors(['package' => 'Project created, but the materials could not be stored: '.$e->getMessage()]);
        }

        return redirect()->route('projects.show', [$project, 'tab' => $tab])->with('status', 'Project created.');
    }

    public function show(Request $request, Project $project, BudgetGuard $budget, CostEstimator $costs, ModelRegistry $registry): View
    {
        Gate::authorize('view', $project);
        $project->load(['packages.voiceover', 'credential.provider', 'videoCredential.provider', 'fallbackCredential.provider']);
        $scenes = $project->scenes()->with(['currentVersion', 'assets'])->get();
        $tab = $request->string('tab', 'overview')->toString();
        $model = $project->credential ? $registry->find($project->credential->provider->slug, $project->ai_model ?: (string) $project->credential->default_model) : null;
        $cueCount = $project->approvedPackage ? count(app(\App\Services\Production\SrtParser::class)->parse($project->approvedPackage->srt_text)) : 0;

        return view('projects.show', [
            'project' => $project, 'tab' => $tab, 'scenes' => $scenes,
            'package' => $project->latestPackage, 'approved' => $project->approvedPackage,
            'files' => $project->files()->latest()->get(),
            'jobs' => $project->generationJobs()->take(20)->get(),
            'renderJobs' => $project->renderJobs()->take(5)->get(),
            'final' => $project->finalVideo(), 'thumbnail' => $project->thumbnails()->first(), 'caption' => $project->captions()->first(),
            'checks' => $project->qualityChecks()->get()->groupBy('kind'),
            'spent' => $project->spentUsd(), 'budgetPct' => $budget->projectUsagePct($project),
            'estimate' => [
                'storyboard' => $model ? $costs->preflight($model, 12000 + $cueCount * 120, 120 * max(1, $cueCount) + 1500) : null,
                'scenes' => $model ? $costs->preflight($model, 9000, 3500) * max(1, $scenes->where('kind', 'story')->count() ?: (int) ceil($cueCount / 3)) : null,
                'model' => $model?->model_id,
            ],
            'blockers' => app(ProductionWorkflow::class)->renderBlockers($project),
            'activity' => $project->activity()->with('user')->take(30)->get(),
            'aiRequests' => $project->aiRequests()->with('provider')->latest()->take(15)->get(),
        ] + $this->aiOptions($request->user(), $registry));
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        $rules = collect((new ProjectRequest)->setUserResolver(fn () => $request->user())->rules());
        $v = $request->validate($rules->only(['title', 'description', 'genre', 'target_duration_seconds'])->merge($rules->filter(fn ($r, $k) => str_starts_with($k, 'settings.')))->all());
        $project->update(collect($v)->except('settings')->all() + ['settings' => array_replace_recursive($project->settings ?? [], $v['settings'] ?? [])]);

        return back()->with('status', 'Project settings saved.');
    }

    public function updateAi(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        $creds = AiCredential::visibleTo($request->user())->pluck('id')->all();
        $d = $request->validate([
            'generation_strategy' => ['required', Rule::in(['ai', 'template'])],
            'ai_credential_id' => ['nullable', Rule::in($creds)], 'ai_model' => ['nullable', 'string', 'max:120'],
            'video_credential_id' => ['nullable', Rule::in($creds)], 'video_model' => ['nullable', 'string', 'max:120'],
            'fallback_enabled' => ['boolean'], 'fallback_credential_id' => ['nullable', Rule::in($creds)], 'fallback_model' => ['nullable', 'string', 'max:120'],
            'budget_limit_usd' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);
        $project->update($d + ['fallback_enabled' => $request->boolean('fallback_enabled')]);
        $project->log('project.ai_updated', 'AI configuration updated.', collect($d)->only(['generation_strategy', 'ai_model', 'video_model', 'budget_limit_usd'])->all());

        return back()->with('status', 'AI configuration saved.');
    }

    public function approveOverrun(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        $d = $request->validate(['amount_usd' => ['required', 'numeric', 'min:0.01', 'max:10000']]);
        $project->increment('approved_overrun_usd', $d['amount_usd']);
        $project->log('budget.overrun_approved', sprintf('Budget overrun of $%.2f approved.', $d['amount_usd']));

        return back()->with('status', sprintf('Approved an extra $%.2f for this project.', $d['amount_usd']));
    }

    public function addMember(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);
        $d = $request->validate(['email' => ['required', 'email', 'exists:users,email'], 'role' => ['required', Rule::in(['viewer', 'editor'])]]);
        $user = User::where('email', $d['email'])->firstOrFail();
        abort_if($user->id === $project->user_id, 422, 'The owner already has access.');
        $project->members()->syncWithoutDetaching([$user->id => ['role' => $d['role']]]);

        return back()->with('status', "{$user->name} added as {$d['role']}.");
    }

    public function destroy(Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);
        Storage::disk(config('motionforge.disk'))->deleteDirectory($project->storageDir());
        $project->delete();

        return redirect()->route('projects.index')->with('status', 'Project deleted.');
    }
}

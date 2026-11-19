<?php

namespace App\Providers;

use App\Contracts\AnimationEngineInterface;
use App\Models\AiCredential;
use App\Models\Project;
use App\Policies\AiCredentialPolicy;
use App\Policies\ProjectPolicy;
use App\Services\AI\AIManager;
use App\Services\AI\Providers\ClaudeProvider;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\KimiProvider;
use App\Services\AI\Providers\OpenAIProvider;
use App\Services\Rendering\KikitoonsEngine;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AIManager::class, fn () => new AIManager([
            'claude' => new ClaudeProvider,
            'gemini' => new GeminiProvider,
            'openai' => new OpenAIProvider,
            'kimi' => new KimiProvider,
        ]));
        $this->app->singleton(KikitoonsEngine::class);
        $this->app->bind(AnimationEngineInterface::class, KikitoonsEngine::class);
    }

    public function boot(): void
    {
        DevCommands::register(
            'cd public && php -d post_max_size=320M -d upload_max_filesize=300M -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
            'server',
        );

        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(AiCredential::class, AiCredentialPolicy::class);
        Gate::define('admin', fn ($user) => $user->isAdmin());
        Gate::define('manage-credentials', fn ($user) => $user->hasPermission('credentials.manage'));
        Gate::define('create-projects', fn ($user) => $user->hasPermission('projects.create'));
        Gate::define('render', fn ($user) => $user->hasPermission('render.start'));

        RateLimiter::for('ai', fn (Request $r) => Limit::perMinute(20)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(15)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(6)->by(strtolower((string) $r->input('email')).'|'.$r->ip()));
    }
}

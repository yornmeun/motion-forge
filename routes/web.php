<?php

use App\Http\Controllers\AiStudioController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\ModelPreferenceController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\PreproductionController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\QualityController;
use App\Http\Controllers\RenderController;
use App\Http\Controllers\SceneEditorController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StoryboardController;
use App\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/register', [AuthController::class, 'storeRegistration'])->middleware('throttle:login')->name('register.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // projects
    Route::resource('projects', ProjectController::class)->except(['edit']);
    Route::patch('/projects/{project}/ai', [ProjectController::class, 'updateAi'])->name('projects.ai');
    Route::post('/projects/{project}/budget-overrun', [ProjectController::class, 'approveOverrun'])->name('projects.overrun');
    Route::post('/projects/{project}/members', [ProjectController::class, 'addMember'])->name('projects.members.store');

    // production package (Workflow A) and pre-production (Workflow B)
    Route::post('/projects/{project}/packages', [PackageController::class, 'store'])->middleware('throttle:uploads')->name('packages.store');
    Route::post('/projects/{project}/packages/{package}/validate', [PackageController::class, 'validatePackage'])->name('packages.validate');
    Route::post('/projects/{project}/packages/{package}/approve', [PackageController::class, 'approve'])->name('packages.approve');
    Route::post('/projects/{project}/preproduction/draft', [PreproductionController::class, 'draft'])->middleware('throttle:ai')->name('preproduction.draft');
    Route::put('/projects/{project}/preproduction', [PreproductionController::class, 'save'])->name('preproduction.save');
    Route::post('/projects/{project}/preproduction/package', [PreproductionController::class, 'package'])->middleware('throttle:uploads')->name('preproduction.package');

    // storyboard & scenes
    Route::post('/projects/{project}/storyboard', [StoryboardController::class, 'generate'])->middleware('throttle:ai')->name('storyboard.generate');
    Route::post('/projects/{project}/scenes/generate', [StoryboardController::class, 'generateScenes'])->middleware('throttle:ai')->name('scenes.generate');
    Route::post('/projects/{project}/scenes', [StoryboardController::class, 'addScene'])->name('scenes.store');

    // scene editor
    Route::get('/editor', [SceneEditorController::class, 'picker'])->name('editor.picker');
    Route::get('/projects/{project}/editor', [SceneEditorController::class, 'show'])->name('editor.show');
    Route::get('/projects/{project}/editor/state', [SceneEditorController::class, 'state'])->name('editor.state');
    Route::patch('/projects/{project}/scenes/{scene}', [SceneEditorController::class, 'update'])->name('scenes.update');
    Route::post('/projects/{project}/scenes/{scene}/regenerate', [SceneEditorController::class, 'regenerate'])->middleware('throttle:ai')->name('scenes.regenerate');
    Route::post('/projects/{project}/scenes/{scene}/revert', [SceneEditorController::class, 'revert'])->name('scenes.revert');
    Route::post('/projects/{project}/scenes/{scene}/timing', [SceneEditorController::class, 'timing'])->name('scenes.timing');
    Route::post('/projects/{project}/scenes/{scene}/code', [SceneEditorController::class, 'code'])->name('scenes.code');
    Route::post('/projects/{project}/scenes/{scene}/source', [SceneEditorController::class, 'source'])->name('scenes.source');
    Route::post('/projects/{project}/scenes/{scene}/clips/{asset}', [SceneEditorController::class, 'clipDecision'])->name('scenes.clip');
    Route::post('/projects/{project}/scenes/order', [SceneEditorController::class, 'reorder'])->name('scenes.reorder');
    Route::get('/projects/{project}/scenes/{scene}/player', [SceneEditorController::class, 'player'])->name('scenes.player');

    // rendering
    Route::get('/render-queue', [RenderController::class, 'queue'])->name('render.queue');
    Route::post('/projects/{project}/render', [RenderController::class, 'start'])->name('render.start');
    Route::post('/projects/{project}/scenes/{scene}/render', [RenderController::class, 'scene'])->name('render.scene');
    Route::post('/render-jobs/{renderJob}/cancel', [RenderController::class, 'cancel'])->name('render.cancel');
    Route::get('/projects/{project}/status', [RenderController::class, 'status'])->name('render.status');
    Route::post('/jobs/{job}/cancel', [JobController::class, 'cancel'])->name('jobs.cancel');
    Route::post('/jobs/{job}/retry', [JobController::class, 'retry'])->name('jobs.retry');

    // quality + delivery
    Route::post('/projects/{project}/quality/{check}', [QualityController::class, 'review'])->name('quality.review');
    Route::get('/videos', [DeliveryController::class, 'index'])->name('videos.index');
    Route::get('/projects/{project}/download/{type}', [DeliveryController::class, 'download'])->whereIn('type', ['video', 'thumbnail', 'srt'])->name('delivery.download');
    Route::get('/projects/{project}/media/{kind}/{id}', [MediaController::class, 'show'])->name('media.show');

    // libraries
    Route::get('/characters', [CharacterController::class, 'index'])->name('characters.index');
    Route::post('/characters', [CharacterController::class, 'store'])->name('characters.store');
    Route::delete('/characters/{character}', [CharacterController::class, 'destroy'])->name('characters.destroy');
    Route::get('/characters/{character}/preview', [CharacterController::class, 'preview'])->name('characters.preview');
    Route::get('/assets', [AssetController::class, 'index'])->name('assets.index');

    // AI
    Route::get('/studio', [AiStudioController::class, 'index'])->name('studio.index');
    Route::post('/studio', [AiStudioController::class, 'run'])->middleware('throttle:ai')->name('studio.run');
    Route::get('/usage', [UsageController::class, 'index'])->name('usage.index');
    Route::post('/usage/budget', [UsageController::class, 'budget'])->name('usage.budget');
    Route::post('/usage/{aiRequest}/confirm', [UsageController::class, 'confirm'])->name('usage.confirm');

    // settings
    Route::get('/settings/profile', [SettingsController::class, 'profile'])->name('settings.profile');
    Route::put('/settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile.update');
    Route::get('/settings/security', [SettingsController::class, 'security'])->name('settings.security');
    Route::put('/settings/security', [SettingsController::class, 'updatePassword'])->name('settings.security.update');
    Route::get('/settings/rendering', [SettingsController::class, 'rendering'])->name('settings.rendering');
    Route::get('/settings/ai-providers', [CredentialController::class, 'providers'])->name('providers.index');
    Route::get('/settings/api-credentials', [CredentialController::class, 'index'])->name('credentials.index');
    Route::post('/settings/api-credentials', [CredentialController::class, 'store'])->middleware('throttle:ai')->name('credentials.store');
    Route::patch('/settings/api-credentials/{credential}', [CredentialController::class, 'update'])->name('credentials.update');
    Route::post('/settings/api-credentials/{credential}/test', [CredentialController::class, 'test'])->middleware('throttle:ai')->name('credentials.test');
    Route::delete('/settings/api-credentials/{credential}', [CredentialController::class, 'destroy'])->name('credentials.destroy');
    Route::get('/settings/models', [ModelPreferenceController::class, 'index'])->name('models.index');
    Route::patch('/settings/models/{model}', [ModelPreferenceController::class, 'update'])->name('models.update');
});

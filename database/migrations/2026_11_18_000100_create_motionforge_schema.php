<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MotionForge schema. Written to run on SQLite (the configured database) and also on PostgreSQL/MySQL.
 * Costs are stored as decimals with estimated and confirmed amounts kept in separate columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ---------- access control ---------- */
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('permission_role', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('role_id')->nullable()->after('password')->constrained()->nullOnDelete();
            $t->decimal('monthly_budget_usd', 12, 4)->nullable();
            $t->unsignedTinyInteger('budget_warning_pct')->default(80);
        });

        /* ---------- AI providers ---------- */
        Schema::create('ai_providers', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('name');
            $t->string('base_url');
            $t->string('docs_url')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('ai_models', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ai_provider_id')->constrained()->cascadeOnDelete();
            $t->string('model_id');
            $t->string('display_name')->nullable();
            $t->json('capabilities');
            $t->decimal('input_price_per_mtok', 12, 4)->nullable();
            $t->decimal('output_price_per_mtok', 12, 4)->nullable();
            $t->decimal('video_price_per_second', 12, 4)->nullable();
            $t->string('pricing_source')->nullable();
            $t->date('pricing_verified_at')->nullable();
            $t->json('limits')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('is_registry')->default(true); // false = discovered via listModels, capabilities unverified
            $t->timestamp('discovered_at')->nullable();
            $t->timestamps();
            $t->unique(['ai_provider_id', 'model_id']);
        });
        Schema::create('ai_credentials', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // null = server-managed (env)
            $t->foreignId('ai_provider_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->text('secret')->nullable(); // encrypted cast; null for env credentials
            $t->string('env_key')->nullable();
            $t->string('key_hint', 16)->nullable();
            $t->string('default_model')->nullable();
            $t->boolean('is_enabled')->default(true);
            $t->string('status')->default('unverified');
            $t->string('status_message')->nullable();
            $t->timestamp('last_tested_at')->nullable();
            $t->timestamp('rotated_at')->nullable();
            $t->decimal('monthly_limit_usd', 12, 4)->nullable();
            $t->timestamps();
        });

        /* ---------- projects ---------- */
        Schema::create('projects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('genre')->nullable();
            $t->string('production_mode');           // kikitoons | native_video | hybrid
            $t->string('workflow')->default('approved_package'); // approved_package | preproduction
            $t->string('aspect_ratio')->default('9:16');
            $t->unsignedSmallInteger('width')->default(1080);
            $t->unsignedSmallInteger('height')->default(1920);
            $t->unsignedSmallInteger('frame_rate')->default(30);
            $t->unsignedInteger('target_duration_seconds')->nullable();
            $t->string('status')->default('draft');
            $t->string('generation_strategy')->default('ai'); // ai | template
            $t->foreignUuid('ai_credential_id')->nullable()->constrained('ai_credentials')->nullOnDelete();
            $t->string('ai_model')->nullable();
            $t->foreignUuid('video_credential_id')->nullable()->constrained('ai_credentials')->nullOnDelete();
            $t->string('video_model')->nullable();
            $t->boolean('fallback_enabled')->default(false);
            $t->foreignUuid('fallback_credential_id')->nullable()->constrained('ai_credentials')->nullOnDelete();
            $t->string('fallback_model')->nullable();
            $t->decimal('budget_limit_usd', 12, 4)->nullable();
            $t->decimal('approved_overrun_usd', 12, 4)->default(0);
            $t->json('settings')->nullable();
            $t->timestamps();
        });
        Schema::create('project_members', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role')->default('viewer'); // editor | viewer
            $t->timestamps();
            $t->unique(['project_id', 'user_id']);
        });
        Schema::create('project_files', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('role'); // story | narration | srt | voiceover | character_ref | notes | music | archive | other
            $t->string('disk')->default('local');
            $t->string('path');
            $t->string('original_name');
            $t->string('mime')->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->string('sha256', 64);
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('production_packages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->string('source')->default('upload'); // upload | preproduction
            $t->string('status')->default('draft');  // draft | validating | valid | invalid | approved | superseded
            $t->longText('locked_story')->nullable();
            $t->longText('narration_text')->nullable();
            $t->longText('srt_text')->nullable();
            $t->foreignUuid('voiceover_file_id')->nullable()->constrained('project_files')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->string('story_hash', 64)->nullable();
            $t->string('narration_hash', 64)->nullable();
            $t->string('srt_hash', 64)->nullable();
            $t->string('voice_hash', 64)->nullable();
            $t->decimal('audio_duration', 10, 3)->nullable();
            $t->decimal('srt_end', 10, 3)->nullable();
            $t->json('validation_report')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('subtitle_tracks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('production_package_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('type'); // source | aligned | final
            $t->json('cues');
            $t->longText('srt_text')->nullable();
            $t->timestamps();
        });

        /* ---------- scenes ---------- */
        Schema::create('scenes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('sequence');
            $t->decimal('start_time', 10, 3);
            $t->decimal('end_time', 10, 3);
            $t->integer('cue_start')->nullable();
            $t->integer('cue_end')->nullable();
            $t->text('narration_reference')->nullable();
            $t->string('kind')->default('story');   // story | end_card
            $t->string('source')->default('canvas'); // canvas | ai_video
            $t->string('status')->default('planned');
            $t->uuid('current_version_id')->nullable();
            $t->foreignUuid('ai_credential_id')->nullable()->constrained('ai_credentials')->nullOnDelete();
            $t->string('ai_model')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();
            $t->index(['project_id', 'sequence']);
        });
        Schema::create('scene_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('scene_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->json('plan');
            $t->longText('code')->nullable();
            $t->json('code_validation')->nullable();
            $t->string('origin'); // ai | template | manual | revert
            $t->uuid('ai_request_id')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('notes')->nullable();
            $t->timestamps();
            $t->unique(['scene_id', 'version']);
        });
        Schema::create('scene_assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('scene_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('scene_version_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type');   // preview_png | scene_video | ai_video | ai_image
            $t->string('path');
            $t->string('origin'); // render:engine | ai:<provider>/<model> | upload
            $t->string('status')->default('ready'); // pending | ready | failed | approved | rejected
            $t->json('metadata')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });

        /* ---------- library ---------- */
        Schema::create('characters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('slug');
            $t->string('name');
            $t->string('kind'); // kiki_preset | kid | suit | custom
            $t->text('description')->nullable();
            $t->json('definition');
            $t->boolean('is_builtin')->default(false);
            $t->timestamps();
            $t->unique(['user_id', 'slug']);
        });
        Schema::create('character_assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('character_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('project_file_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type'); // reference | preview
            $t->string('path');
            $t->timestamps();
        });
        Schema::create('background_assets', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('name');
            $t->string('engine_function');
            $t->text('description')->nullable();
            $t->string('default_look')->default('neutral');
            $t->string('ambience')->default('room_tone');
            $t->boolean('is_builtin')->default(true);
            $t->timestamps();
        });
        Schema::create('audio_assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->string('type'); // voice_track | bed | mix | cue_sheet
            $t->string('path');
            $t->decimal('duration', 10, 3)->nullable();
            $t->decimal('loudness_lufs', 6, 2)->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });

        /* ---------- jobs & rendering ---------- */
        Schema::create('generation_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('scene_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type');
            $t->string('status')->default('queued'); // queued | running | succeeded | failed | cancelled | waiting_approval
            $t->string('stage')->nullable();
            $t->unsignedInteger('progress_current')->nullable();
            $t->unsignedInteger('progress_total')->nullable();
            $t->string('idempotency_key')->nullable()->unique();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->text('error')->nullable();
            $t->json('payload')->nullable();
            $t->json('result')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('cancel_requested_at')->nullable();
            $t->timestamps();
            $t->index(['project_id', 'status']);
        });
        Schema::create('render_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type'); // full | scenes | thumbnail | preview
            $t->string('status')->default('queued');
            $t->string('stage')->nullable();
            $t->unsignedInteger('progress_current')->nullable();
            $t->unsignedInteger('progress_total')->nullable();
            $t->json('scene_ids')->nullable();
            $t->string('idempotency_key')->nullable()->unique();
            $t->text('error')->nullable();
            $t->longText('log')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('cancel_requested_at')->nullable();
            $t->timestamps();
        });
        Schema::create('render_artifacts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('render_job_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('scene_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type'); // scene_video | video_silent | mix | final_video | thumbnail | story_js | caps | srt_final
            $t->string('path');
            $t->unsignedBigInteger('size')->default(0);
            $t->string('sha256', 64)->nullable();
            $t->json('metadata')->nullable();
            $t->boolean('is_delivery')->default(false);
            $t->timestamps();
        });
        Schema::create('quality_checks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('render_artifact_id')->nullable()->constrained()->nullOnDelete();
            $t->string('kind'); // technical | creative
            $t->string('check_key');
            $t->string('status'); // pass | warn | fail | pending
            $t->string('value')->nullable();
            $t->string('expected')->nullable();
            $t->text('message')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::create('thumbnails', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('render_artifact_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title_text');
            $t->string('path');
            $t->json('checks')->nullable();
            $t->timestamps();
        });
        Schema::create('captions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $t->text('body');
            $t->json('hashtags');
            $t->string('origin'); // ai:<provider>/<model> | template | manual
            $t->uuid('ai_request_id')->nullable();
            $t->timestamps();
        });

        /* ---------- AI usage ---------- */
        Schema::create('ai_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('scene_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('ai_credential_id')->nullable()->constrained('ai_credentials')->nullOnDelete();
            $t->foreignId('ai_provider_id')->constrained();
            $t->string('model');
            $t->string('capability');
            $t->string('request_type');
            $t->string('status')->default('pending'); // pending | succeeded | failed | refused | blocked_budget
            $t->string('idempotency_key')->nullable()->unique();
            $t->unsignedInteger('input_tokens')->nullable();
            $t->unsignedInteger('output_tokens')->nullable();
            $t->decimal('media_units', 12, 3)->nullable(); // e.g. seconds of generated video
            $t->unsignedInteger('duration_ms')->nullable();
            $t->decimal('estimated_cost_usd', 14, 6)->nullable();
            $t->decimal('confirmed_cost_usd', 14, 6)->nullable();
            $t->string('provider_request_id')->nullable();
            $t->text('error_message')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });
        Schema::create('ai_usage_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('ai_request_id')->nullable()->constrained()->nullOnDelete();
            $t->string('provider_slug');
            $t->string('model');
            $t->string('period', 7); // YYYY-MM
            $t->decimal('estimated_cost_usd', 14, 6)->default(0);
            $t->decimal('confirmed_cost_usd', 14, 6)->nullable();
            $t->string('source')->default('estimate'); // estimate | provider_report | manual
            $t->string('notes')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'period']);
        });

        /* ---------- audit / settings ---------- */
        Schema::create('project_activity_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('project_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action');
            $t->text('description');
            $t->json('properties')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['project_id', 'created_at']);
        });
        Schema::create('system_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['system_settings', 'project_activity_logs', 'ai_usage_records', 'ai_requests', 'captions', 'thumbnails', 'quality_checks', 'render_artifacts', 'render_jobs', 'generation_jobs', 'audio_assets', 'background_assets', 'character_assets', 'characters', 'scene_assets', 'scene_versions', 'scenes', 'subtitle_tracks', 'production_packages', 'project_files', 'project_members', 'projects', 'ai_credentials', 'ai_models', 'ai_providers'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('role_id');
            $t->dropColumn(['monthly_budget_usd', 'budget_warning_pct']);
        });
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};

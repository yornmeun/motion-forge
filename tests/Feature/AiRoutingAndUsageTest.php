<?php

namespace Tests\Feature;

use App\Enums\AICapability;
use App\Enums\ProviderStatus;
use App\Exceptions\AIProviderException;
use App\Exceptions\BudgetExceededException;
use App\Models\AiModel;
use App\Models\AiRequest;
use App\Models\AiUsageRecord;
use App\Services\AI\AIManager;
use App\Services\AI\ProviderRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MotionForgeFixtures;
use Tests\TestCase;

/** Provider calls are mocked (Http::fake). Results here say nothing about real provider behaviour. */
class AiRoutingAndUsageTest extends TestCase
{
    use MotionForgeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRegistry();
        // give the Kimi model a test price so cost tracking can be asserted (real price is unverified)
        AiModel::where('model_id', 'kimi-k3')->update(['input_price_per_mtok' => 1.0, 'output_price_per_mtok' => 2.0]);
    }

    private function fakeKimi(string $content = '{"ok":true}', int $in = 1000, int $out = 500): void
    {
        Http::fake(['api.moonshot.ai/v1/chat/completions' => Http::response([
            'id' => 'cmpl-test', 'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out],
        ])]);
    }

    public function test_manual_model_selection_uses_the_chosen_model(): void
    {
        $user = $this->userWithRole();
        $cred = $this->credential($user);
        $route = app(ProviderRouter::class)->resolve($user, AICapability::ScenePlanning, $cred->id, 'kimi-k3');
        $this->assertSame('kimi-k3', $route->model->model_id);
        $this->assertSame('kimi', $route->provider->slug());
    }

    public function test_unsupported_capability_is_rejected_before_any_call(): void
    {
        Http::fake();
        $user = $this->userWithRole();
        $cred = $this->credential($user);

        $this->expectException(AIProviderException::class);
        try {
            app(ProviderRouter::class)->resolve($user, AICapability::NativeVideo, $cred->id, 'kimi-k3');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_unconnected_credential_cannot_be_routed(): void
    {
        $user = $this->userWithRole();
        $cred = $this->credential($user, status: ProviderStatus::Invalid);
        $this->expectException(AIProviderException::class);
        app(ProviderRouter::class)->resolve($user, AICapability::ScenePlanning, $cred->id);
    }

    public function test_a_successful_call_records_tokens_cost_and_usage(): void
    {
        $this->fakeKimi('{"answer":42}', 1000, 500);
        $user = $this->userWithRole();
        $route = app(ProviderRouter::class)->resolve($user, AICapability::ScenePlanning, $this->credential($user)->id, 'kimi-k3');

        $res = app(AIManager::class)->call($route, $user, AICapability::ScenePlanning, 'test', ['messages' => [['role' => 'user', 'content' => 'hi']]], ['type' => 'object']);

        $this->assertSame(['answer' => 42], $res->json);
        $req = AiRequest::sole();
        $this->assertSame('succeeded', $req->status);
        $this->assertSame(1000, (int) $req->input_tokens);
        $this->assertSame(500, (int) $req->output_tokens);
        // 1000/1e6 * $1 + 500/1e6 * $2 = $0.002
        $this->assertEqualsWithDelta(0.002, (float) $req->estimated_cost_usd, 1e-9);
        $this->assertEqualsWithDelta(0.002, (float) AiUsageRecord::sole()->estimated_cost_usd, 1e-9);
        $this->assertSame('kimi', AiUsageRecord::sole()->provider_slug);
    }

    public function test_idempotency_key_prevents_paying_twice(): void
    {
        $this->fakeKimi();
        $user = $this->userWithRole();
        $route = app(ProviderRouter::class)->resolve($user, AICapability::ScenePlanning, $this->credential($user)->id, 'kimi-k3');
        $ai = app(AIManager::class);
        $msg = ['messages' => [['role' => 'user', 'content' => 'hi']]];

        $ai->call($route, $user, AICapability::ScenePlanning, 'test', $msg, idempotencyKey: 'same-key');
        $second = $ai->call($route, $user, AICapability::ScenePlanning, 'test', $msg, idempotencyKey: 'same-key');

        Http::assertSentCount(1);
        $this->assertTrue($second->meta['cached'] ?? false);
        $this->assertSame(1, AiRequest::count());
    }

    public function test_project_budget_blocks_the_call_and_records_it(): void
    {
        Http::fake();
        $user = $this->userWithRole();
        $project = $this->project($user, ['budget_limit_usd' => 0.0001]);
        $route = app(ProviderRouter::class)->resolve($user, AICapability::ScenePlanning, $this->credential($user)->id, 'kimi-k3');

        try {
            app(AIManager::class)->call($route, $user, AICapability::ScenePlanning, 'test', ['messages' => [['role' => 'user', 'content' => str_repeat('x', 4000)]]], project: $project, expectedOutputTokens: 4000);
            $this->fail('Expected the budget to block the call.');
        } catch (BudgetExceededException) {
        }
        Http::assertNothingSent();
        $this->assertSame('blocked_budget', AiRequest::sole()->status);
    }

    public function test_provider_auth_error_marks_the_credential_invalid_and_hides_the_key(): void
    {
        Http::fake(['api.moonshot.ai/*' => Http::response(['error' => ['message' => 'bad key sk-test-0123456789abcdef']], 401)]);
        $user = $this->userWithRole();
        $cred = $this->credential($user);
        $route = app(ProviderRouter::class)->resolve($user, AICapability::ScenePlanning, $cred->id, 'kimi-k3');

        try {
            app(AIManager::class)->call($route, $user, AICapability::ScenePlanning, 'test', ['messages' => [['role' => 'user', 'content' => 'hi']]]);
            $this->fail('Expected an auth error.');
        } catch (AIProviderException $e) {
            $this->assertSame('auth', $e->kind);
        }
        $this->assertSame(ProviderStatus::Invalid, $cred->fresh()->status);
        $this->assertStringNotContainsString('0123456789abcdef', (string) AiRequest::sole()->error_message);
    }
}

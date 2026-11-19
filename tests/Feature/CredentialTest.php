<?php

namespace Tests\Feature;

use App\Enums\ProviderStatus;
use App\Models\AiCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MotionForgeFixtures;
use Tests\TestCase;

/** All provider HTTP traffic in this file is mocked with Http::fake; no real API is called. */
class CredentialTest extends TestCase
{
    use MotionForgeFixtures, RefreshDatabase;

    private const KEY = 'sk-test-SECRETVALUE-123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRegistry();
    }

    public function test_api_key_is_encrypted_at_rest_and_never_rendered(): void
    {
        Http::fake(['api.moonshot.ai/*' => Http::response(['data' => [['id' => 'kimi-k3']]])]);
        $user = $this->userWithRole();
        $this->actingAs($user)->post('/settings/api-credentials', ['provider' => 'kimi', 'name' => 'My Kimi', 'api_key' => self::KEY])->assertRedirect();

        $raw = DB::table('ai_credentials')->value('secret');
        $this->assertNotSame(self::KEY, $raw);
        $this->assertStringNotContainsString('SECRETVALUE', $raw);
        $this->assertSame(self::KEY, AiCredential::first()->apiKey());
        $this->assertArrayNotHasKey('secret', AiCredential::first()->toArray());

        $page = $this->actingAs($user)->get('/settings/api-credentials')->assertOk()->getContent();
        $this->assertStringNotContainsString('SECRETVALUE', $page);
        $this->assertStringContainsString('3456', $page); // only the last four characters are shown
    }

    public function test_connection_test_with_a_good_key_marks_the_credential_connected(): void
    {
        Http::fake(['api.moonshot.ai/v1/models' => Http::response(['data' => [['id' => 'kimi-k3'], ['id' => 'kimi-experimental-x']]])]);
        $user = $this->userWithRole();
        $this->actingAs($user)->post('/settings/api-credentials', ['provider' => 'kimi', 'name' => 'Good', 'api_key' => self::KEY]);

        $cred = AiCredential::first();
        $this->assertSame(ProviderStatus::Connected, $cred->status);
        $this->assertNotNull($cred->last_tested_at);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer '.self::KEY) && str_ends_with($r->url(), '/v1/models'));
        // discovered models are stored without capabilities
        $this->assertDatabaseHas('ai_models', ['model_id' => 'kimi-experimental-x', 'is_registry' => false]);
    }

    public function test_connection_test_with_a_bad_key_marks_it_invalid_and_scrubs_the_key(): void
    {
        Http::fake(['api.moonshot.ai/*' => Http::response(['error' => ['message' => 'Invalid key '.self::KEY]], 401)]);
        $user = $this->userWithRole();
        $this->actingAs($user)->post('/settings/api-credentials', ['provider' => 'kimi', 'name' => 'Bad', 'api_key' => self::KEY]);

        $cred = AiCredential::first();
        $this->assertSame(ProviderStatus::Invalid, $cred->status);
        $this->assertStringNotContainsString('SECRETVALUE', (string) $cred->status_message);
        $this->assertStringNotContainsString('SECRETVALUE', (string) session('status'));
    }

    public function test_users_cannot_use_or_delete_someone_elses_credential(): void
    {
        $owner = $this->userWithRole();
        $other = $this->userWithRole();
        $cred = $this->credential($owner);

        $this->actingAs($other)->post("/settings/api-credentials/{$cred->id}/test")->assertForbidden();
        $this->actingAs($other)->delete("/settings/api-credentials/{$cred->id}")->assertForbidden();
        $this->assertDatabaseHas('ai_credentials', ['id' => $cred->id]);
    }

    public function test_rotating_a_key_retests_it(): void
    {
        Http::fake(['api.moonshot.ai/*' => Http::response(['data' => []])]);
        $user = $this->userWithRole();
        $cred = $this->credential($user, status: ProviderStatus::Invalid);
        $this->actingAs($user)->patch("/settings/api-credentials/{$cred->id}", ['api_key' => 'sk-new-key-abcdefghijkl'])->assertRedirect();

        $cred->refresh();
        $this->assertSame('sk-new-key-abcdefghijkl', $cred->apiKey());
        $this->assertSame('ijkl', $cred->key_hint);
        $this->assertNotNull($cred->rotated_at);
        $this->assertSame(ProviderStatus::Connected, $cred->status);
    }
}

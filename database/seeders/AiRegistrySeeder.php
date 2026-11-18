<?php

namespace Database\Seeders;

use App\Models\AiCredential;
use App\Models\AiProvider;
use App\Services\AI\ModelRegistry;
use Illuminate\Database\Seeder;

class AiRegistrySeeder extends Seeder
{
    public function run(): void
    {
        app(ModelRegistry::class)->syncFromConfig();
        // server-managed connections exist only when the env key is set; the key itself is never stored
        foreach (config('motionforge.providers') as $slug => $p) {
            $key = config('motionforge.server_keys.'.$p['env_key']);
            if ($key) {
                AiCredential::firstOrCreate(['user_id' => null, 'env_key' => $p['env_key']], ['ai_provider_id' => AiProvider::where('slug', $slug)->value('id'), 'name' => $p['name'].' (server)', 'status' => 'unverified', 'key_hint' => substr($key, -4)]);
            }
        }
    }
}

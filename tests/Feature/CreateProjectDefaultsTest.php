<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateProjectDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_form_defaults_to_the_free_director_and_youtube_landscape(): void
    {
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create(['role_id' => Role::where('slug', 'admin')->value('id')]);

        $response = $this->actingAs($user)->get(route('projects.create'));

        $response->assertOk();
        $response->assertSee('<option value="template" selected>Built-in director (free)</option>', false);
        $response->assertSee('<option value="30" selected>30 fps (Kikitoons standard)</option>', false);
        $response->assertSee("format: '1920x1080'", false);
        $response->assertSee('name="archive"', false);
    }
}

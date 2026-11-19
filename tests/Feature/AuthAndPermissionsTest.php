<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MotionForgeFixtures;
use Tests\TestCase;

class AuthAndPermissionsTest extends TestCase
{
    use MotionForgeFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRegistry();
    }

    public function test_first_account_is_admin_and_later_accounts_are_producers(): void
    {
        $pw = 'correct-horse-battery';
        $this->post('/register', ['name' => 'First', 'email' => 'first@example.test', 'password' => $pw, 'password_confirmation' => $pw])->assertRedirect('/dashboard');
        $this->post('/logout');
        $this->post('/register', ['name' => 'Second', 'email' => 'second@example.test', 'password' => $pw, 'password_confirmation' => $pw])->assertRedirect('/dashboard');

        $this->assertSame('admin', User::where('email', 'first@example.test')->first()->role->slug);
        $this->assertSame('producer', User::where('email', 'second@example.test')->first()->role->slug);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/projects')->assertRedirect('/login');
    }

    public function test_viewer_role_cannot_create_projects_or_manage_credentials(): void
    {
        $viewer = $this->userWithRole('viewer');
        $this->actingAs($viewer)->get('/projects/create')->assertForbidden();
        $this->actingAs($viewer)->post('/settings/api-credentials', ['provider' => 'kimi', 'name' => 'x', 'api_key' => 'sk-abcdefghijklmnop'])->assertForbidden();
    }

    public function test_project_access_follows_ownership_and_membership(): void
    {
        $owner = $this->userWithRole();
        $stranger = $this->userWithRole();
        $member = $this->userWithRole();
        $project = $this->project($owner);
        $project->members()->attach($member->id, ['role' => 'viewer']);

        $this->actingAs($owner)->get("/projects/{$project->id}")->assertOk();
        $this->actingAs($stranger)->get("/projects/{$project->id}")->assertForbidden();
        $this->actingAs($member)->get("/projects/{$project->id}")->assertOk();
        // a viewer member may look but not change or delete
        $this->actingAs($member)->patch("/projects/{$project->id}", ['title' => 'Renamed'])->assertForbidden();
        $this->actingAs($member)->delete("/projects/{$project->id}")->assertForbidden();
        $this->assertSame('Test Project', $project->fresh()->title);
    }

    public function test_project_creation_through_the_wizard_endpoint(): void
    {
        $user = $this->userWithRole();
        $res = $this->actingAs($user)->post('/projects', [
            'title' => 'Wizard Test', 'production_mode' => 'kikitoons', 'workflow' => 'approved_package',
            'aspect_ratio' => '9:16', 'resolution' => '1080x1920', 'frame_rate' => 30, 'generation_strategy' => 'template',
        ]);
        $project = $user->projects()->first();
        $this->assertNotNull($project);
        $res->assertRedirect(route('projects.show', [$project, 'tab' => 'package']));
        $this->assertSame('Wizard Test', $project->title);
        $this->assertSame(1080, $project->width);
        $this->assertSame(1920, $project->height);
        $this->assertSame('draft', $project->status->value);
    }

    public function test_project_creation_rejects_invalid_format_values(): void
    {
        $user = $this->userWithRole();
        $this->actingAs($user)->post('/projects', [
            'title' => 'Bad', 'production_mode' => 'kikitoons', 'workflow' => 'approved_package',
            'aspect_ratio' => '4:3', 'resolution' => '640x480', 'frame_rate' => 60, 'generation_strategy' => 'template',
        ])->assertSessionHasErrors(['aspect_ratio', 'resolution', 'frame_rate']);
        $this->assertSame(0, $user->projects()->count());
    }
}

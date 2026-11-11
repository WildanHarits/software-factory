<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_super_admin_with_a_personal_team()
    {
        $this->artisan('app:create-super-admin', [
            '--name' => 'First Admin',
            '--email' => 'first@example.com',
            '--password' => 'secret-password',
        ])->assertSuccessful();

        $user = User::where('email', 'first@example.com')->firstOrFail();

        $this->assertTrue($user->is_super_admin);
        $this->assertNotNull($user->personalTeam());
    }

    public function test_it_promotes_an_existing_user()
    {
        $user = User::factory()->create(['email' => 'existing@example.com']);

        $this->artisan('app:create-super-admin', ['--email' => 'existing@example.com'])
            ->expectsConfirmation('existing@example.com already exists. Make them a super admin?', 'yes')
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->is_super_admin);
    }

    public function test_it_rejects_invalid_input()
    {
        $this->artisan('app:create-super-admin', [
            '--name' => 'Bad',
            '--email' => 'not-an-email',
            '--password' => 'secret-password',
        ])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}

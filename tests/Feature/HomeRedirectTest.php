<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_login()
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_users_are_sent_to_their_current_team_dashboard()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(route('dashboard', ['current_team' => $user->currentTeam->slug]));
    }

    public function test_registration_is_not_available()
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Someone',
            'email' => 'someone@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'someone@example.com']);
    }
}

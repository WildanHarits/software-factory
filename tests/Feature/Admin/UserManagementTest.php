<?php

namespace Tests\Feature\Admin;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    public function test_super_admins_can_view_the_users_page()
    {
        $admin = $this->superAdmin();
        User::factory()->count(2)->create();

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/index')
                ->has('users', 3)
                ->has('roles', 2));
    }

    public function test_regular_users_cannot_access_admin_pages()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.users.store'), [])->assertForbidden();
        $this->actingAs($user)->patch(route('admin.users.update', $other), [])->assertForbidden();
        $this->actingAs($user)->delete(route('admin.users.destroy', $other))->assertForbidden();
    }

    public function test_guests_are_redirected_to_login()
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
    }

    public function test_super_admins_can_create_users_with_a_personal_team()
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.users.store'), [
                'name' => 'New Person',
                'email' => 'new@example.com',
                'password' => 'secret-password',
                'team_id' => 'none',
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors();

        $user = User::where('email', 'new@example.com')->firstOrFail();

        $this->assertFalse($user->is_super_admin);
        $this->assertTrue(Hash::check('secret-password', $user->password));
        $this->assertNotNull($user->personalTeam());
        $this->assertTrue($user->currentTeam->is($user->personalTeam()));
    }

    public function test_super_admins_can_create_users_inside_a_team()
    {
        $team = Team::factory()->create();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.users.store'), [
                'name' => 'Team Person',
                'email' => 'team@example.com',
                'password' => 'secret-password',
                'team_id' => $team->id,
                'role' => TeamRole::Admin->value,
                'is_super_admin' => '1',
            ])
            ->assertSessionHasNoErrors();

        $user = User::where('email', 'team@example.com')->firstOrFail();

        $this->assertTrue($user->is_super_admin);
        $this->assertSame(TeamRole::Admin, $user->teamRole($team));
        $this->assertTrue($user->currentTeam->is($team));
    }

    public function test_users_cannot_be_added_to_a_team_as_owner_or_to_a_personal_team()
    {
        $admin = $this->superAdmin();
        $team = Team::factory()->create();

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Owner Wannabe',
                'email' => 'owner@example.com',
                'password' => 'secret-password',
                'team_id' => $team->id,
                'role' => TeamRole::Owner->value,
            ])
            ->assertSessionHasErrors('role');

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'Personal Intruder',
                'email' => 'intruder@example.com',
                'password' => 'secret-password',
                'team_id' => $admin->personalTeam()->id,
                'role' => TeamRole::Member->value,
            ])
            ->assertSessionHasErrors('team_id');

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.com']);
    }

    public function test_emails_must_be_unique()
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.users.store'), [
                'name' => 'Duplicate',
                'email' => 'taken@example.com',
                'password' => 'secret-password',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_super_admins_can_update_users_and_keep_the_password_when_left_empty()
    {
        $user = User::factory()->create();
        $oldHash = $user->password;

        $this->actingAs($this->superAdmin())
            ->patch(route('admin.users.update', $user), [
                'name' => 'Renamed',
                'email' => 'renamed@example.com',
                'password' => '',
                'is_super_admin' => '1',
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('Renamed', $user->name);
        $this->assertSame('renamed@example.com', $user->email);
        $this->assertTrue($user->is_super_admin);
        $this->assertSame($oldHash, $user->password);
    }

    public function test_super_admins_can_reset_a_password()
    {
        $user = User::factory()->create();

        $this->actingAs($this->superAdmin())
            ->patch(route('admin.users.update', $user), [
                'name' => $user->name,
                'email' => $user->email,
                'password' => 'brand-new-password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertFalse($user->fresh()->is_super_admin);
    }

    public function test_super_admins_cannot_remove_their_own_access()
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
            ])
            ->assertSessionHasErrors('is_super_admin');

        $this->assertTrue($admin->fresh()->is_super_admin);
    }

    public function test_super_admins_can_delete_users_and_their_personal_team()
    {
        $user = User::factory()->create();
        $personalTeam = $user->personalTeam();

        $this->actingAs($this->superAdmin())
            ->delete(route('admin.users.destroy', $user))
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($user);
        $this->assertSoftDeleted($personalTeam);
    }

    public function test_users_who_own_a_shared_team_cannot_be_deleted()
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['name' => 'Shared Team']);
        $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

        $this->actingAs($this->superAdmin())
            ->delete(route('admin.users.destroy', $user))
            ->assertSessionHasErrors('user');

        $this->assertModelExists($user);
    }

    public function test_super_admins_cannot_delete_themselves()
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $admin))
            ->assertSessionHasErrors('user');

        $this->assertModelExists($admin);
    }
}

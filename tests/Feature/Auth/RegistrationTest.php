<?php

namespace Tests\Feature\Auth;

use App\Enums\OrganizationRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register()
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_registration_creates_a_personal_organization_owned_by_the_user()
    {
        $this->post('/register', [
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'grace@example.com')->firstOrFail();

        $this->assertNotNull($user->currentOrganization);
        $this->assertSame(OrganizationRole::Owner, $user->roleIn($user->currentOrganization));
    }

    public function test_registration_cannot_grant_super_admin()
    {
        $this->post('/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'is_super_admin' => true,
        ]);

        $this->assertFalse(User::where('email', 'mallory@example.com')->firstOrFail()->is_super_admin);
    }
}

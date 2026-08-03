<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_and_public_registration_does_not_exist(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/register')->assertNotFound();
    }

    public function test_admin_can_sign_in_and_sign_out(): void
    {
        $user = User::factory()->create(['password' => 'rahasia-rpp']);

        $this->post('/login', ['email' => $user->email, 'password' => 'rahasia-rpp'])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}

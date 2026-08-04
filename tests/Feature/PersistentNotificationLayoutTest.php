<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersistentNotificationLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_layout_contains_fixed_dismissible_notification_center(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['notice' => 'Pesan pengujian'])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Pusat notifikasi')
            ->assertSee('Tutup notifikasi')
            ->assertSee('fixed inset-x-4 top-20', false)
            ->assertSee('Pesan pengujian');
    }
}

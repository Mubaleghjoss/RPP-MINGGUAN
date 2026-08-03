<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $password = env('ADMIN_PASSWORD', 'ubah-password-admin-ini');
        if (app()->environment('production') && $password === 'ubah-password-admin-ini') {
            throw new \RuntimeException('ADMIN_PASSWORD wajib diganti sebelum seed pada production.');
        }

        User::query()->updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@rpp.local')],
            [
                'name' => env('ADMIN_NAME', 'Administrator RPP'),
                'password' => $password,
                'email_verified_at' => now(),
            ]
        );

        $this->call(CurriculumSeeder::class);
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create a test user
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password123')
        ]);

        // Create some additional random users
        User::factory()->count(5)->create();

        User::factory()->create(
            [
                'name' => 'Adeyinka',
                'username' => 'Yinkax86',
                'email' => 'adeyinka.giwa36@gmail.com',
                'zivos' =>  1000,
                'email_verified_at' => now(),
                'password' => Hash::make('Secret19'),
                'remember_token' => 'secret',
            ]
        );

        User::factory()->create(
            [
                'name' => 'Yolanda',
                'username' => 'Yolandax86',
                'email' => 'kymakurumure@hotmail.com',
                'zivos' =>  1000,
                'email_verified_at' => now(),
                'password' => Hash::make('Secret19'),
                'remember_token' => 'secret',
            ]
        );
    }
}

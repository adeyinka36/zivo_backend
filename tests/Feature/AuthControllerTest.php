<?php

namespace Tests\Feature;

use App\Exceptions\Auth\GoogleSignInRequiredException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register(): void
    {
        $userData = [
            'name' => 'Test User',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ];

        $response = $this->postJson('/api/v1/register', $userData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'user' => [
                        'id',
                        'name',
                        'username',
                        'email',
                        'created_at',
                        'updated_at',
                    ],
                    'token',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'username' => 'testuser',
        ]);
    }

    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('Password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'token',
                    'user' => [
                        'id',
                        'name',
                        'username',
                        'email',
                        'created_at',
                        'updated_at',
                    ],
                ],
            ]);
    }

    public function test_user_cannot_register_with_existing_email(): void
    {
        $email = 'john.register.duplicate@gmail.com';

        User::factory()->create([
            'email' => $email,
            'username' => 'existing_user',
        ]);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Existing User',
            'username' => 'existing_user',
            'email' => $email,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email'])
            ->assertJsonFragment([
                'email' => ['An account with this email already exists. Please log in instead.'],
            ]);
    }

    public function test_user_cannot_login_with_password_when_registered_via_google(): void
    {
        $email = 'john.google.login@gmail.com';

        User::factory()->create([
            'email' => $email,
            'google_id' => 'google-oauth-id-123',
            'password' => null,
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => $email,
            'password' => 'Password123',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => GoogleSignInRequiredException::MESSAGE,
            ]);
    }

    public function test_forgot_password_rejects_google_registered_email(): void
    {
        $email = 'john.google.reset@gmail.com';

        User::factory()->create([
            'email' => $email,
            'google_id' => 'google-oauth-id-456',
            'password' => null,
        ]);

        $response = $this->postJson('/api/v1/password-email', [
            'email' => $email,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => GoogleSignInRequiredException::MESSAGE,
            ]);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/logout');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Successfully logged out',
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
    }
} 
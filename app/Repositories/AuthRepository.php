<?php

namespace App\Repositories;

use App\Models\User;
use App\Repositories\Contracts\AuthRepositoryInterface;
use Illuminate\Support\Facades\Hash;

class AuthRepository implements AuthRepositoryInterface
{
    public function authenticate(string $email, string $password): ?User
    {
        $normalized = mb_strtolower($email);
        $user = User::whereRaw('LOWER(email) = ?', [$normalized])->first();
        if ($user && Hash::check($password, $user->password)) {
            return $user;
        }
        return null;
    }

    public function createToken(User $user, string $name = 'auth_token'): string
    {
        return $user->createToken($name)->plainTextToken;
    }

    public function revokeAllTokens(User $user): void
    {
        $user->tokens()->delete();
    }

    public function revokeToken(User $user, string $token): void
    {
        $user->tokens()->where('token', hash('sha256', $token))->delete();
    }
}

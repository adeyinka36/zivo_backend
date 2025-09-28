<?php

namespace App\Repositories\Contracts;

use App\Models\User;

interface AuthRepositoryInterface
{
    public function authenticate(string $email, string $password): ?User;
    public function createToken(User $user, string $name = 'auth_token'): string;
    public function revokeAllTokens(User $user): void;
    public function revokeToken(User $user, string $token): void;
}

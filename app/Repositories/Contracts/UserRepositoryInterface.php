<?php

namespace App\Repositories\Contracts;

use App\DTOs\Auth\AppleUserDTO;
use App\DTOs\Auth\GoogleUserDTO;
use App\Models\User;

interface UserRepositoryInterface
{
    public function create(array $data): User;
    public function findByEmail(string $email): ?User;
    public function findById(string $id): ?User;
    public function findOrCreateFromGoogle(GoogleUserDTO $googleUser): User;
    public function findOrCreateFromApple(AppleUserDTO $appleUser): User;
    public function update(User $user, array $data): User;
    public function delete(User $user): bool;
}

<?php

namespace App\Repositories;

use App\DTOs\Auth\GoogleUserDTO;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;

class UserRepository implements UserRepositoryInterface
{
    public function create(array $data): User
    {
        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        return User::create($data);
    }

    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function findById(string $id): ?User
    {
        return User::find($id);
    }

    public function findOrCreateFromGoogle(GoogleUserDTO $googleUser): User
    {
        $oauthData = array_filter([
            'google_token' => $googleUser->token,
            'google_refresh_token' => $googleUser->refreshToken,
            'avatar' => $googleUser->avatar,
        ], fn ($value) => $value !== null && $value !== '');

        $verifiedAttributes = ['email_verified_at' => now()];

        $user = User::where('google_id', $googleUser->id)->first();

        if ($user) {
            return $this->update($user, array_merge($oauthData, [
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]));
        }

        $user = User::whereRaw('LOWER(email) = ?', [$googleUser->email])->first();

        if ($user) {
            return $this->update($user, array_merge($oauthData, [
                'google_id' => $googleUser->id,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]));
        }

        return $this->create(array_merge($oauthData, $verifiedAttributes, [
            'name' => $googleUser->name,
            'username' => $this->generateUniqueUsername($googleUser),
            'email' => $googleUser->email,
            'google_id' => $googleUser->id,
        ]));
    }

    public function update(User $user, array $data): User
    {
        $user->update($data);
        return $user->fresh();
    }

    public function delete(User $user): bool
    {
        return $user->delete();
    }

    private function generateUniqueUsername(GoogleUserDTO $googleUser): string
    {
        $nameSlug = strtolower(preg_replace('/[^a-z0-9]/', '', $googleUser->name) ?? '');
        $emailPrefix = explode('@', $googleUser->email)[0];
        $base = ($nameSlug !== '' ? $nameSlug : 'user').'_'.$emailPrefix;

        $username = $base;
        $suffix = 1;

        while (User::where('username', $username)->exists()) {
            $username = $base.'_'.$suffix;
            $suffix++;
        }

        return $username;
    }
}

<?php

namespace App\DTOs\Auth;

use Laravel\Socialite\Contracts\User as SocialiteUser;

readonly class GoogleUserDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public ?string $avatar,
        public ?string $token = null,
        public ?string $refreshToken = null,
    ) {}

    public static function fromSocialiteUser(SocialiteUser $user): self
    {
        return new self(
            id: (string) $user->getId(),
            name: $user->getName() ?? 'Google User',
            email: mb_strtolower($user->getEmail()),
            avatar: $user->getAvatar(),
            token: $user->token,
            refreshToken: $user->refreshToken,
        );
    }

    public static function fromIdTokenPayload(object $payload): self
    {
        return new self(
            id: (string) $payload->sub,
            name: $payload->name ?? 'Google User',
            email: mb_strtolower($payload->email),
            avatar: $payload->picture ?? null,
        );
    }
}

<?php

namespace App\DTOs\Auth;

readonly class AppleUserDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email,
    ) {}

    public static function fromIdTokenPayload(object $payload, ?string $name = null, ?string $email = null): self
    {
        $resolvedEmail = $email ?? (isset($payload->email) ? mb_strtolower((string) $payload->email) : null);
        $resolvedName = $name ?? 'Apple User';

        return new self(
            id: (string) $payload->sub,
            name: $resolvedName,
            email: $resolvedEmail,
        );
    }
}

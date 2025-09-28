<?php

namespace App\DTOs\Auth;

readonly class PasswordResetDTO
{
    public function __construct(
        public string $email,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            email: $data['email'],
        );
    }
}

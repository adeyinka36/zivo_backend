<?php

namespace App\DTOs\Auth;

readonly class LoginCredentialsDTO
{
    public function __construct(
        public string $email,
        public string $password,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            email: $data['email'],
            password: $data['password'],
        );
    }
}

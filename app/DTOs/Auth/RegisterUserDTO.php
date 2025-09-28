<?php

namespace App\DTOs\Auth;

readonly class RegisterUserDTO
{
    public function __construct(
        public string $name,
        public string $username,
        public string $email,
        public string $password,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            name: $data['name'],
            username: $data['username'],
            email: $data['email'],
            password: $data['password'],
        );
    }
}

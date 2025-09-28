<?php

namespace App\DTOs\Auth;

use App\Models\User;

readonly class AuthResponseDTO
{
    public function __construct(
        public User $user,
        public string $token,
        public string $message = 'Success',
    ) {}

    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'data' => [
                'user' => new \App\Http\Resources\UserResource($this->user),
                'token' => $this->token,
            ]
        ];
    }
}

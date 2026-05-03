<?php

namespace App\DTOs\Auth;

use App\Models\User;

readonly class AuthResponseDTO
{
    public function __construct(
        public User $user,
        public string $token,
        public string $message = 'Success',
        public ?string $refreshToken = null,
    ) {}

    public function toArray(): array
    {
        $data = [
            'user' => new \App\Http\Resources\UserResource($this->user),
            'token' => $this->token,
        ];

        if ($this->refreshToken !== null) {
            $data['refreshToken'] = $this->refreshToken;
        }

        return [
            'message' => $this->message,
            'data' => $data,
        ];
    }
}

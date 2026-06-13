<?php

namespace App\Actions\Auth;

use App\DTOs\Auth\AuthResponseDTO;
use App\DTOs\Auth\GoogleUserDTO;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Auth\AuthService;
use App\Services\Auth\GoogleIdTokenVerifier;

class AuthenticateWithGoogleIdTokenAction
{
    public function __construct(
        private GoogleIdTokenVerifier $googleIdTokenVerifier,
        private UserRepositoryInterface $userRepository,
        private AuthService $authService,
    ) {}

    public function execute(string $idToken): AuthResponseDTO
    {
        $payload = $this->googleIdTokenVerifier->verify($idToken);
        $googleUser = GoogleUserDTO::fromIdTokenPayload($payload);
        $user = $this->userRepository->findOrCreateFromGoogle($googleUser);

        return $this->authService->authenticateUser(
            $user,
            'Google login successful',
        );
    }
}

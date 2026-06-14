<?php

namespace App\Actions\Auth;

use App\DTOs\Auth\AppleUserDTO;
use App\DTOs\Auth\AuthResponseDTO;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Auth\AppleIdentityTokenVerifier;
use App\Services\Auth\AuthService;

class AuthenticateWithAppleIdentityTokenAction
{
    public function __construct(
        private AppleIdentityTokenVerifier $appleIdentityTokenVerifier,
        private UserRepositoryInterface $userRepository,
        private AuthService $authService,
    ) {}

    public function execute(string $identityToken, ?string $name = null, ?string $email = null): AuthResponseDTO
    {
        $payload = $this->appleIdentityTokenVerifier->verify($identityToken);
        $appleUser = AppleUserDTO::fromIdTokenPayload($payload, $name, $email);
        $user = $this->userRepository->findOrCreateFromApple($appleUser);

        return $this->authService->authenticateUser(
            $user,
            'Apple login successful',
        );
    }
}

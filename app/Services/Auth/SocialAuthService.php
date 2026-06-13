<?php

namespace App\Services\Auth;

use App\Actions\Auth\AuthenticateWithGoogleIdTokenAction;
use App\Actions\Auth\HandleGoogleCallbackAction;
use App\Actions\Auth\RedirectToGoogleAction;
use App\DTOs\Auth\AuthResponseDTO;
use Symfony\Component\HttpFoundation\RedirectResponse;

readonly class SocialAuthService
{
    public function __construct(
        private RedirectToGoogleAction $redirectToGoogleAction,
        private HandleGoogleCallbackAction $handleGoogleCallbackAction,
        private AuthenticateWithGoogleIdTokenAction $authenticateWithGoogleIdTokenAction,
    ) {}

    public function redirectToGoogle(): RedirectResponse
    {
        return $this->redirectToGoogleAction->execute();
    }

    public function handleGoogleCallback(): AuthResponseDTO
    {
        return $this->handleGoogleCallbackAction->execute();
    }

    public function loginWithIdToken(string $idToken): AuthResponseDTO
    {
        return $this->authenticateWithGoogleIdTokenAction->execute($idToken);
    }
}

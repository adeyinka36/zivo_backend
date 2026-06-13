<?php

namespace App\Actions\Auth;

use App\DTOs\Auth\AuthResponseDTO;
use App\DTOs\Auth\GoogleUserDTO;
use App\Exceptions\Auth\SocialAuthFailedException;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Auth\AuthService;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class HandleGoogleCallbackAction
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private AuthService $authService,
    ) {}

    /**
     * @throws SocialAuthFailedException
     */
    public function execute(): AuthResponseDTO
    {
        try {
            $socialiteUser = Socialite::driver('google')->stateless()->user();
            $googleUser = GoogleUserDTO::fromSocialiteUser($socialiteUser);
            $user = $this->userRepository->findOrCreateFromGoogle($googleUser);

            return $this->authService->authenticateUser(
                $user,
                'Google login successful',
            );
        } catch (SocialAuthFailedException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Google OAuth callback failed', [
                'error' => $e->getMessage(),
            ]);

            throw new SocialAuthFailedException();
        }
    }
}

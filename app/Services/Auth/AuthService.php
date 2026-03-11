<?php

namespace App\Services\Auth;

use App\DTOs\Auth\AuthResponseDTO;
use App\DTOs\Auth\LoginCredentialsDTO;
use App\DTOs\Auth\RegisterUserDTO;
use App\DTOs\Auth\PasswordResetDTO;
use App\Models\User;
use App\Repositories\Contracts\AuthRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Exceptions\Auth\RegistrationFailedException;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Log;

readonly class AuthService
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private AuthRepositoryInterface $authRepository,
    ) {}

    public function register(RegisterUserDTO $dto): AuthResponseDTO
    {
        try {
            $user = $this->userRepository->create([
                'name' => $dto->name,
                'username' => $dto->username,
                'email' => mb_strtolower($dto->email),
                'password' => $dto->password,
            ]);

            $user->sendEmailVerificationNotification();
            $token = $this->authRepository->createToken($user);

            return new AuthResponseDTO(
                user: $user,
                token: $token,
                message: 'Registration successful. Please check your email for verification.'
            );
        } catch (\Exception $e) {
            Log::error('Registration failed', [
                'email' => $dto->email,
                'error' => $e->getMessage()
            ]);
            throw new RegistrationFailedException('Registration failed. Please try again.');
        }
    }

    public function login(LoginCredentialsDTO $dto): AuthResponseDTO
    {
        $user = $this->authRepository->authenticate($dto->email, $dto->password);
        
        if (!$user) {
            throw new InvalidCredentialsException('Invalid credentials');
        }

        $token = $this->authRepository->createToken($user);

        return new AuthResponseDTO(
            user: $user,
            token: $token,
            message: 'Login successful'
        );
    }

    public function logout(User $user): void
    {
        $this->authRepository->revokeAllTokens($user);
        
        Log::info('User logged out', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);
    }

    public function forgotPassword(PasswordResetDTO $dto): void
    {
        $user = User::whereRaw('LOWER(email) = ?', [mb_strtolower($dto->email)])->first();
        if (!$user) {
            throw new \Exception('Unable to send password reset link');
        }
        $status = Password::sendResetLink(['email' => $user->email]);
        if ($status !== Password::RESET_LINK_SENT) {
            throw new \Exception('Unable to send password reset link');
        }
    }
}

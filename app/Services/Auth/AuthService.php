<?php

namespace App\Services\Auth;

use App\DTOs\Auth\AuthResponseDTO;
use App\DTOs\Auth\LoginCredentialsDTO;
use App\DTOs\Auth\RegisterUserDTO;
use App\DTOs\Auth\PasswordResetDTO;
use App\Repositories\Contracts\AuthRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Exceptions\Auth\InvalidRefreshTokenException;
use App\Exceptions\Auth\RegistrationFailedException;
use App\Models\User;
use App\Models\UserRefreshToken;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
            $refreshPlain = $this->issueRefreshTokenForUser($user);

            return new AuthResponseDTO(
                user: $user,
                token: $token,
                message: 'Registration successful. Please check your email for verification.',
                refreshToken: $refreshPlain,
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

        return $this->authenticateUser($user);
    }

    public function authenticateUser(User $user, string $message = 'Login successful'): AuthResponseDTO
    {
        $token = $this->authRepository->createToken($user);
        $refreshPlain = $this->issueRefreshTokenForUser($user);

        return new AuthResponseDTO(
            user: $user,
            token: $token,
            message: $message,
            refreshToken: $refreshPlain,
        );
    }

    /**
     * Exchange a valid refresh token for new access + refresh tokens (rotation).
     *
     * @throws InvalidRefreshTokenException
     */
    public function refreshAccessToken(string $plainRefreshToken): AuthResponseDTO
    {
        $hash = hash('sha256', $plainRefreshToken);
        $record = UserRefreshToken::where('token_hash', $hash)->first();

        if (! $record || $record->expires_at->isPast()) {
            if ($record) {
                $record->delete();
            }
            throw new InvalidRefreshTokenException();
        }

        $user = User::find($record->user_id);

        if (! $user) {
            $record->delete();
            throw new InvalidRefreshTokenException();
        }

        $record->delete();

        $accessToken = $this->authRepository->createToken($user);
        $newRefreshPlain = $this->issueRefreshTokenForUser($user);

        return new AuthResponseDTO(
            user: $user,
            token: $accessToken,
            message: 'Token refreshed',
            refreshToken: $newRefreshPlain,
        );
    }

    public function logout(User $user): void
    {
        UserRefreshToken::where('user_id', $user->id)->delete();
        $this->authRepository->revokeAllTokens($user);
        
        Log::info('User logged out', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);
    }

    private function issueRefreshTokenForUser(User $user): string
    {
        $plain = Str::random(64);
        UserRefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays((int) config('auth.refresh_token_expiration_days', 90)),
        ]);

        return $plain;
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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\UpdateEmailRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Services\Auth\AuthService;
use App\Exceptions\Auth\InvalidRefreshTokenException;
use App\Services\NotificationService;
use App\DTOs\Auth\RegisterUserDTO;
use App\DTOs\Auth\LoginCredentialsDTO;
use App\DTOs\Auth\PasswordResetDTO;
use App\Http\Responses\SuccessResponse;
use App\Http\Responses\ErrorResponse;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Exceptions\Auth\GoogleSignInRequiredException;
use App\Exceptions\Auth\RegistrationFailedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\View\View;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService,
        private NotificationService $notificationService
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $dto = RegisterUserDTO::fromRequest($request->validated());
            $response = $this->authService->register($dto);
            
            return SuccessResponse::make(
                $response->message,
                $response->toArray()['data'],
                201
            );
        } catch (RegistrationFailedException $e) {
            return ErrorResponse::make($e->getMessage(), $e->getCode());
        }
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $dto = LoginCredentialsDTO::fromRequest($request->validated());
            $response = $this->authService->login($dto);
            
            return SuccessResponse::make(
                $response->message,
                $response->toArray()['data']
            );
        } catch (InvalidCredentialsException $e) {
            return ErrorResponse::make($e->getMessage(), $e->getCode());
        }
    }

    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        try {
            $response = $this->authService->refreshAccessToken($request->validated('refresh_token'));

            return SuccessResponse::make(
                $response->message,
                $response->toArray()['data']
            );
        } catch (InvalidRefreshTokenException $e) {
            return ErrorResponse::make($e->getMessage(), 401);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $this->authService->logout($request->user());
            
            return SuccessResponse::make('Successfully logged out');
        } catch (\Exception $e) {
            return SuccessResponse::make('Logout completed');
        }
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        try {
            $dto = PasswordResetDTO::fromRequest($request->validated());
            $this->authService->forgotPassword($dto);
            
            return SuccessResponse::make('Password reset link sent to your email');
        } catch (GoogleSignInRequiredException $e) {
            return ErrorResponse::make($e->getMessage(), $e->getCode());
        } catch (\Exception $e) {
            return ErrorResponse::make('Unable to send password reset link', 500);
        }
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->setRememberToken(Str::random(60));

                $user->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return SuccessResponse::make('Password has been reset successfully');
        }

        return ErrorResponse::make('Unable to reset password', 400);
    }

    /**
     * Verify user email address.
     * Returns HTML view for browser requests, JSON for API requests.
     */
    public function verifyEmail(Request $request): JsonResponse|View
    {
        $user = \App\Models\User::find($request->route('id'));

        if (!$user || !hash_equals((string) $request->route('hash'), sha1($user->getEmailForVerification()))) {
            if ($request->expectsJson() || $request->wantsJson()) {
                return ErrorResponse::make('Invalid verification link', 400);
            }
            return view('email-verification-error', [
                'message' => 'This verification link is invalid or has expired.'
            ]);
        }

        if ($user->hasVerifiedEmail()) {
            if ($request->expectsJson() || $request->wantsJson()) {
                return SuccessResponse::make('Email already verified');
            }
            return view('email-verified');
        }

        if ($user->markEmailAsVerified()) {
            event(new \Illuminate\Auth\Events\Verified($user));
            
            if ($user->push_notifications && $user->push_token) {
                $this->notificationService->sendEmailVerificationSuccess($user);
            }
        }

        if ($request->expectsJson() || $request->wantsJson()) {
            return SuccessResponse::make('Email verified successfully');
        }

        return view('email-verified');
    }

    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return ErrorResponse::make('Email already verified', 400);
        }

        $user->sendEmailVerificationNotification();

        return SuccessResponse::make('Verification email sent successfully please check '. $user->email);
    }

    public function updateEmail(UpdateEmailRequest $request): JsonResponse
    {
        $user = $request->user();
        $newEmail = $request->validated()['email'];

        if ($user->email === $newEmail) {
            return ErrorResponse::make('New email must be different from current email', 400);
        }

        $user->update([
            'email' => $newEmail,
            'email_verified_at' => null
        ]);

        $user->sendEmailVerificationNotification();

        return SuccessResponse::make('Email updated successfully. Please check your new email for verification.');
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user instanceof \App\Models\User) {
            return ErrorResponse::make('Unauthorized. User authentication required.', 401);
        }

        try {
            DB::transaction(function () use ($user): void {
                $user->tokens()->delete();

                /*
                 * Delete directly via query builder to avoid model-level media-library
                 * hooks attempting polymorphic media lookups against the custom media table schema.
                 */
                DB::table('users')->where('id', $user->id)->delete();
            });

            return SuccessResponse::make('Account deleted successfully');
        } catch (Throwable $e) {
            Log::error('Failed to delete user account', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return ErrorResponse::make('Failed to delete account. Please try again.', 500);
        }
    }
}
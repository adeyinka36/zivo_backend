<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Auth\AuthService;
use App\DTOs\Auth\RegisterUserDTO;
use App\DTOs\Auth\LoginCredentialsDTO;
use App\DTOs\Auth\PasswordResetDTO;
use App\Http\Responses\SuccessResponse;
use App\Http\Responses\ErrorResponse;
use App\Exceptions\Auth\InvalidCredentialsException;
use App\Exceptions\Auth\RegistrationFailedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService
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

    public function verifyEmail(Request $request): JsonResponse
    {
        $user = \App\Models\User::find($request->route('id'));

        if (!$user || !hash_equals((string) $request->route('hash'), sha1($user->getEmailForVerification()))) {
            return ErrorResponse::make('Invalid verification link', 400);
        }

        if ($user->hasVerifiedEmail()) {
            return SuccessResponse::make('Email already verified');
        }

        if ($user->markEmailAsVerified()) {
            event(new \Illuminate\Auth\Events\Verified($user));
        }

        return SuccessResponse::make('Email verified successfully');
    }
}
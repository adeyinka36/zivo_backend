<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Auth\SocialAuthFailedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleLoginRequest;
use App\Http\Responses\ErrorResponse;
use App\Http\Responses\SuccessResponse;
use App\Services\Auth\SocialAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SocialAuthController extends Controller
{
    public function __construct(
        private readonly SocialAuthService $socialAuthService,
    ) {}

    public function login(GoogleLoginRequest $request): JsonResponse
    {
        try {
            $response = $this->socialAuthService->loginWithIdToken(
                $request->validated('id_token'),
            );

            return SuccessResponse::make(
                $response->message,
                $response->toArray()['data'],
            );
        } catch (SocialAuthFailedException $e) {
            return ErrorResponse::make($e->getMessage(), $e->getCode());
        }
    }

    public function redirect(): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return $this->socialAuthService->redirectToGoogle();
    }

    public function callback(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $response = $this->socialAuthService->handleGoogleCallback();

            if ($request->expectsJson()) {
                return SuccessResponse::make(
                    $response->message,
                    $response->toArray()['data'],
                );
            }

            $redirectUrl = config('services.google.frontend_redirect');
            $query = http_build_query([
                'token' => $response->token,
                'refresh_token' => $response->refreshToken,
            ]);

            return redirect()->away(rtrim($redirectUrl, '/').'?'.$query);
        } catch (SocialAuthFailedException $e) {
            if ($request->expectsJson()) {
                return ErrorResponse::make($e->getMessage(), $e->getCode());
            }

            $errorRedirect = config('services.google.frontend_redirect');

            return redirect()->away(
                rtrim($errorRedirect, '/').'?'.http_build_query(['error' => $e->getMessage()])
            );
        }
    }
}

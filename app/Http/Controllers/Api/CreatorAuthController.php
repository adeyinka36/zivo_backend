<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Creator\RegisterCreatorRequest;
use App\Http\Requests\Creator\RegisterCreatorWithVerificationRequest;
use App\Http\Requests\Creator\LoginCreatorRequest;
use App\Http\Requests\Creator\UpdateCreatorVerificationRequest;
use App\Http\Responses\SuccessResponse;
use App\Http\Responses\ErrorResponse;
use App\Models\Creator;
use App\Services\Creator\CreatorAccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class CreatorAuthController extends Controller
{
    public function register(RegisterCreatorRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $creator = Creator::create([
                'name' => $validated['business_name'], // Using business_name as name for now
                'business_name' => $validated['business_name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'secret' => Str::random(60),
            ]);

            $creator->sendEmailVerificationNotification();
            $token = $creator->createToken('creator-auth-token')->plainTextToken;

            return SuccessResponse::make(
                'Registration successful. Please check your email for verification.',
                [
                    'creator' => [
                        'id' => $creator->id,
                        'name' => $creator->name,
                        'business_name' => $creator->business_name,
                        'email' => $creator->email,
                    ],
                    'token' => $token,
                ],
                201
            );
        } catch (\Exception $e) {
            Log::error('Creator registration failed', [
                'email' => $request->input('email'),
                'error' => $e->getMessage(),
            ]);
            return ErrorResponse::make('Registration failed. Please try again.', 500);
        }
    }

    /**
     * Register a new creator with verification documents in a single request.
     */
    public function registerWithVerification(RegisterCreatorWithVerificationRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $file = $request->file('proof_of_address');

            $creator = Creator::create([
                'name' => $validated['business_name'],
                'business_name' => $validated['business_name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'secret' => Str::random(60),
                'address' => $validated['address'],
                'phone' => $validated['phone'] ?? null,
                'is_limited_company' => $validated['is_limited_company'],
                'crn' => $validated['crn'] ?? null,
                'email_verified_at' => now(),
            ]);

            $disk = app()->environment('testing') ? 'public' : 's3';
            $path = 'creators_documents/' . $creator->id . '/' . $file->hashName();

            $file->storeAs(
                'creators_documents/' . $creator->id,
                $file->hashName(),
                ['disk' => $disk]
            );

            $creator->update([
                'proof_of_address_type' => $validated['proof_of_address_type'],
                'proof_of_address_path' => $path,
            ]);

            $creator->refresh();

            $token = $creator->createToken('creator-auth-token')->plainTextToken;

            return SuccessResponse::make(
                'Registration successful. Your account is ready to use.',
                [
                    'creator' => [
                        'id' => $creator->id,
                        'name' => $creator->name,
                        'business_name' => $creator->business_name,
                        'email' => $creator->email,
                        'email_verified_at' => $creator->email_verified_at,
                    ],
                    'token' => $token,
                ],
                201
            );
        } catch (\Exception $e) {
            Log::error('Creator registration with verification failed', [
                'email' => $request->input('email'),
                'error' => $e->getMessage(),
            ]);
            return ErrorResponse::make('Registration failed. Please try again.', 500);
        }
    }

    public function login(LoginCreatorRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            $creator = Creator::whereRaw('LOWER(email) = ?', [mb_strtolower($validated['email'])])->first();

            if (!$creator || !Hash::check($validated['password'], $creator->password)) {
                return ErrorResponse::make('Invalid credentials.', 401);
            }

            if (!$creator->hasVerifiedEmail()) {
                return ErrorResponse::make('Please verify your email address first.', 403);
            }

            $token = $creator->createToken('creator-auth-token')->plainTextToken;

            return SuccessResponse::make(
                'Login successful',
                [
                    'creator' => [
                        'id' => $creator->id,
                        'name' => $creator->name,
                        'business_name' => $creator->business_name,
                        'email' => $creator->email,
                        'email_verified_at' => $creator->email_verified_at,
                    ],
                    'token' => $token,
                ]
            );
        } catch (\Exception $e) {
            Log::error('Creator login failed', [
                'email' => $request->input('email'),
                'error' => $e->getMessage(),
            ]);
            return ErrorResponse::make('Login failed. Please try again.', 500);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            /** @var Creator $creator */
            $creator = $request->user();

            if (!$creator instanceof Creator) {
                return ErrorResponse::make('Unauthorized', 401);
            }

            $creator->tokens()->delete();

            return SuccessResponse::make('Successfully logged out');
        } catch (\Exception $e) {
            Log::error('Creator logout failed', [
                'error' => $e->getMessage(),
            ]);
            return SuccessResponse::make('Logout completed');
        }
    }

    public function me(Request $request): JsonResponse
    {
        try {
            /** @var Creator $creator */
            $creator = $request->user();

            if (!$creator instanceof Creator) {
                return ErrorResponse::make('Unauthorized', 401);
            }

            return SuccessResponse::make(
                'Creator retrieved successfully',
                [
                    'id' => $creator->id,
                    'name' => $creator->name,
                    'business_name' => $creator->business_name,
                    'email' => $creator->email,
                    'email_verified_at' => $creator->email_verified_at,
                    'phone' => $creator->phone,
                    'address' => $creator->address,
                    'crn' => $creator->crn,
                    'is_admin' => $creator->is_admin,
                ]
            );
        } catch (\Exception $e) {
      return ErrorResponse::make('Failed to retrieve creator', 500);
    }
  }

  public function verifyEmail(Request $request): JsonResponse|View
  {
    $creator = Creator::find($request->route('id'));

    if (!$creator || !hash_equals((string) $request->route('hash'), sha1($creator->getEmailForVerification()))) {
      if ($request->expectsJson() || $request->wantsJson()) {
        return ErrorResponse::make('Invalid verification link', 400);
      }
      return view('email-verification-error', [
        'message' => 'This verification link is invalid or has expired.'
      ]);
    }

    if ($creator->hasVerifiedEmail()) {
      if ($request->expectsJson() || $request->wantsJson()) {
        return SuccessResponse::make('Email already verified');
      }
      return view('email-verified');
    }

    if ($creator->markEmailAsVerified()) {
      event(new \Illuminate\Auth\Events\Verified($creator));
    }

    if ($request->expectsJson() || $request->wantsJson()) {
      return SuccessResponse::make('Email verified successfully');
    }

    return view('email-verified');
  }

  public function updateVerification(UpdateCreatorVerificationRequest $request): JsonResponse
  {
    try {
      /** @var Creator $creator */
      $creator = $request->user();

      if (!$creator instanceof Creator) {
        return ErrorResponse::make('Unauthorized', 401);
      }

      $validated = $request->validated();

      // Upload proof of address to S3
      $file = $request->file('proof_of_address');
      $disk = app()->environment('testing') ? 'public' : 's3';
      $path = $disk === 's3' 
        ? 'creators_documents/' . $creator->id . '/' . $file->hashName()
        : 'creators_documents/' . $creator->id . '/' . $file->hashName();

      $file->storeAs(
        $disk === 's3' ? 'creators_documents/' . $creator->id : 'creators_documents/' . $creator->id,
        $file->hashName(),
        ['disk' => $disk]
      );

      // Update creator with verification data
      $updateData = [
        'proof_of_address_type' => $validated['proof_of_address_type'],
        'proof_of_address_path' => $path,
      ];

      if (isset($validated['is_limited_company'])) {
        $updateData['is_limited_company'] = $validated['is_limited_company'];
      }

      if (isset($validated['crn'])) {
        $updateData['crn'] = $validated['crn'];
      }

      $creator->update($updateData);

      if (! $creator->hasVerifiedEmail()) {
        $creator->forceFill(['email_verified_at' => now()])->save();
      }

      return SuccessResponse::make(
        'Business details saved successfully. Your account is ready to use.',
        [
          'creator' => [
            'id' => $creator->id,
            'business_name' => $creator->business_name,
            'proof_of_address_type' => $creator->proof_of_address_type,
            'email_verified_at' => $creator->email_verified_at,
          ],
        ]
      );
    } catch (\Exception $e) {
      Log::error('Creator verification update failed', [
        'creator_id' => $request->user()?->id,
        'error' => $e->getMessage(),
      ]);
      return ErrorResponse::make('Failed to upload verification documents. Please try again.', 500);
    }
  }

    public function deleteAccount(
        Request $request,
        CreatorAccountDeletionService $deletionService
    ): JsonResponse {
        $creator = $request->user();

        if (! $creator instanceof Creator) {
            return ErrorResponse::make('Unauthorized. Creator authentication required.', 401);
        }

        try {
            $deletionService->delete($creator);

            return SuccessResponse::make('Account deleted successfully');
        } catch (\Throwable $e) {
            Log::error('Failed to delete creator account', [
                'creator_id' => $creator->id,
                'error' => $e->getMessage(),
            ]);

            return ErrorResponse::make('Failed to delete account. Please try again.', 500);
        }
    }
}

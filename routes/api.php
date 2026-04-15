<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompaniesHouseController;
use App\Http\Controllers\Api\CreatorAuthController;
use App\Http\Controllers\Api\CreatorCampaignController;
use App\Http\Controllers\Api\CreatorImpersonationController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RewardController;
use App\Http\Controllers\Api\UserLocationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/


// API Version 1 Routes
Route::prefix('v1')->group(function () {
    // Public routes
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/password-email', [AuthController::class, 'forgotPassword'])
        ->name('password.email');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');

    Route::get('/verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware(['signed'])
        ->name('verification.verify');

    // Creator routes (public)
    Route::post('/creators/register', [CreatorAuthController::class, 'register']);
    Route::post('/creators/register-with-verification', [CreatorAuthController::class, 'registerWithVerification']);
    Route::post('/creators/login', [CreatorAuthController::class, 'login']);
    Route::get('/creators/verify-email/{id}/{hash}', [CreatorAuthController::class, 'verifyEmail'])
        ->middleware(['signed'])
        ->name('creators.verification.verify');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', function (Request $request) {
            $user = $request->user();
            if (!$user instanceof \App\Models\User) {
                return response()->json(['error' => 'Unauthorized. User authentication required.'], 401);
            }
            return new \App\Http\Resources\UserResource($user);
        })->middleware('ensure.user');
        Route::post('/logout', [AuthController::class, 'logout'])->middleware('ensure.user');
        Route::post('/resend-verification-email', [AuthController::class, 'resendVerificationEmail'])->middleware('ensure.user');
        Route::post('/update-email', [AuthController::class, 'updateEmail'])->middleware('ensure.user');

        Route::get('/profile', [ProfileController::class, 'show'])->middleware('ensure.user');
        Route::put('/profile', [ProfileController::class, 'update'])->middleware('ensure.user');
        Route::put('/users/push-token', [ProfileController::class, 'updatePushToken'])->middleware('ensure.user');
        Route::get('/user/media', [ProfileController::class, 'getUserMedia'])->middleware('ensure.user');
        Route::get('/user/rewards', [RewardController::class, 'index'])->middleware('ensure.user');
        Route::put('/users/location', [UserLocationController::class, 'update'])->middleware('ensure.user');

        Route::get('locations', [LocationController::class, 'index']);

        Route::get('media', [MediaController::class, 'index']);
        Route::post('media/payment-intent', [MediaController::class, 'createPaymentIntent'])->middleware('ensure.creator');
        Route::post('media/upload-after-payment', [MediaController::class, 'uploadAfterPayment'])->middleware('ensure.creator');
        Route::get('media/{id}', [MediaController::class, 'show']);
        Route::delete('media/{id}', [MediaController::class, 'destroy'])->middleware('ensure.creator');
        Route::post('media-watched/{media}', [MediaController::class, 'markAsWatched'])
            ->middleware('ensure.user');

        Route::get('payments/{paymentId}/status', [PaymentController::class, 'getPaymentStatus'])->middleware('ensure.creator');
        Route::get('payments/history', [PaymentController::class, 'getPaymentHistory'])->middleware('ensure.creator');
        Route::post('payments/{payment}/refund', [PaymentController::class, 'requestRefund'])->middleware('ensure.creator');

        Route::get('/creator/campaigns', [CreatorCampaignController::class, 'index'])->middleware('ensure.creator');
        Route::put('/creator/campaign-codes/{codeId}/claimed', [CreatorCampaignController::class, 'setCodeClaimed'])
            ->middleware('ensure.creator');


        Route::prefix('quiz')->group(function () {
            Route::post('result', [MediaController::class, 'processQuizResult'])
                ->name('quiz.result');
        });

        Route::get('/target-views', [PlatformController::class, 'getTargetViews']);

        Route::get('/companies-house/{crn}', [CompaniesHouseController::class, 'getCompanyDetails'])->middleware('ensure.creator');

        Route::post('/creators/logout', [CreatorAuthController::class, 'logout'])->middleware('ensure.creator');
        Route::get('/creators/me', [CreatorAuthController::class, 'me'])->middleware('ensure.creator');
        Route::post('/creators/update-verification', [CreatorAuthController::class, 'updateVerification'])->middleware('ensure.creator');

        Route::get('/creators', [CreatorImpersonationController::class, 'index'])->middleware(['ensure.creator', 'ensure.admin']);
        Route::post('/creators/impersonate', [CreatorImpersonationController::class, 'impersonate'])->middleware(['ensure.creator', 'ensure.admin']);
    });

    Route::post('webhooks/stripe', [PaymentController::class, 'webhook']);

    Route::get('/health', function () {
        return response()->json([
            'status' => 'success',
            'message' => 'Zivo is live soon',
            'timestamp' => now()
        ]);
    });

    Route::get('/platform-info', [\App\Http\Controllers\Api\PlatformController::class, 'getPlatformInfo']);
});

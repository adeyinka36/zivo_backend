<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\NotificationController;
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

    // Email verification
    Route::get('/verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware(['signed'])
        ->name('verification.verify');

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', function (Request $request) {
            return $request->user();
        });
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/resend-verification-email', [AuthController::class, 'resendVerificationEmail']);

        // Profile routes
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);
        Route::get('/user/media', [ProfileController::class, 'getUserMedia']);

        // Media routes
        Route::get('media', [MediaController::class, 'index']);
        Route::post('media/payment-intent', [MediaController::class, 'createPaymentIntent']);
        Route::post('media/upload-after-payment', [MediaController::class, 'uploadAfterPayment']);
        Route::get('media/{id}', [MediaController::class, 'show']);
        Route::delete('media/{id}', [MediaController::class, 'destroy']);
        Route::post('media-watched/{media}/{user}', [MediaController::class, 'markAsWatched']);

        // Payment routes
        Route::get('payments/{paymentId}/status', [PaymentController::class, 'getPaymentStatus']);
        Route::get('payments/history', [PaymentController::class, 'getPaymentHistory']);
        Route::post('payments/{payment}/refund', [PaymentController::class, 'requestRefund']);

        Route::prefix('push-token')->group(function () {
            Route::post('{user}', [NotificationController::class, 'store']);
        });

        Route::prefix('quiz')->group(function () {
            Route::post('result', [MediaController::class, 'processQuizResult'])
                ->name('quiz.result');
        });
    });

    // Health check
    Route::get('/health', function () {
        return response()->json([
            'status' => 'success',
            'message' => 'API is running',
            'timestamp' => now()
        ]);
    });

    // Webhook route (no auth required)
    Route::post('webhooks/stripe', [PaymentController::class, 'webhook']);
});

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Stripe\StripeClient;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService
    ) {}

    /**
     * Create payment intent for media upload
     */
    public function createPaymentIntent(Request $request, Media $media)
    {
        // Rate limiting
        $key = 'payment_intent_' . $request->user()->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json([
                'message' => 'Too many payment attempts. Please try again later.',
            ], 429);
        }
        RateLimiter::hit($key, 60); // 1 minute window

        // Validate media ownership
        if ($media->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Check if already paid
        if ($media->payment_status === 'paid') {
            return response()->json(['message' => 'Media already paid for'], 400);
        }

        try {
            $result = $this->paymentService->createPaymentIntent($media, $request->user());

            return response()->json([
                'client_secret' => $result['client_secret'],
                'payment_id' => $result['payment_id'],
                'existing' => $result['existing'] ?? false,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create payment intent', [
                'error' => $e->getMessage(),
                'media_id' => $media->id,
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'message' => 'Failed to create payment intent. Please try again.',
            ], 500);
        }
    }

    /**
     * Handle Stripe webhooks
     */
    public function webhook(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');

        // Validate webhook signature
        if (!$this->paymentService->validateWebhookSignature($payload, $signature)) {
            Log::error('Invalid webhook signature');
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                config('stripe.webhook_secret')
            );

            switch ($event->type) {
                case 'payment_intent.succeeded':
                    $payment = $this->paymentService->confirmPayment($event->data->object->id);
                    break;

                case 'payment_intent.payment_failed':
                    $payment = $this->paymentService->handlePaymentFailure(
                        $event->data->object->id,
                        $event->data->object->last_payment_error->message ?? 'Payment failed'
                    );
                    break;

                case 'payment_intent.canceled':
                    $payment = $this->paymentService->handlePaymentFailure(
                        $event->data->object->id,
                        'Payment canceled'
                    );
                    break;

                case 'charge.refunded':
                    // Handle refund webhook if needed
                    break;

                default:
                    Log::info('Unhandled webhook event', ['event_type' => $event->type]);
            }

            return response()->json(['status' => 'success']);

        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    /**
     * Get payment status
     */
    public function getPaymentStatus(Request $request, string $paymentId)
    {
        $payment = Payment::where('id', $paymentId)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        $response = [
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'paid_at' => $payment->paid_at,
            'failure_reason' => $payment->failure_reason,
        ];

        return response()->json($response);
    }

    /**
     * Get user's payment history
     */
    public function getPaymentHistory(Request $request)
    {
        $payments = Payment::where('user_id', $request->user()->id)
            ->with(['media:id,name,file_name'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'payments' => $payments->items(),
            'pagination' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    /**
     * Request refund
     */
    public function requestRefund(Request $request, Payment $payment)
    {
        // Validate payment ownership
        if ($payment->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Validate refund request
        $request->validate([
            'reason' => 'required|string|max:500',
            'amount' => 'nullable|numeric|min:0.01|max:' . $payment->amount,
        ]);

        try {
            $refundResult = $this->paymentService->processRefund(
                $payment,
                $request->input('amount'),
                $request->input('reason')
            );

            return response()->json([
                'message' => 'Refund processed successfully',
                'refund_id' => $refundResult['refund_id'],
                'amount' => $refundResult['amount'],
                'status' => $refundResult['status'],
            ]);

        } catch (\Exception $e) {
            Log::error('Refund request failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Refund request failed: ' . $e->getMessage(),
            ], 500);
        }
    }
} 
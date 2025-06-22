<?php

namespace App\Services;

use App\Models\Media;
use App\Models\Payment;
use App\Models\User;
use App\Repositories\PaymentRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

class PaymentService
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly PaymentRepository $paymentRepository,
        private readonly PaymentLogger $paymentLogger
    ) {}

    /**
     * Create a payment intent for media upload
     */
    public function createPaymentIntent(Media $media, User $user): array
    {
        return DB::transaction(function () use ($media, $user) {
            // Check if payment already exists
            $existingPayment = $this->paymentRepository->findByMediaId($media->id)
                ->where('status', Payment::STATUS_PENDING)
                ->first();

            if ($existingPayment) {
                // Return existing payment intent
                return [
                    'client_secret' => $this->getClientSecret($existingPayment->stripe_payment_intent_id),
                    'payment_id' => $existingPayment->id,
                    'existing' => true,
                ];
            }

            $transactionReference = $this->generateTransactionReference($media->id, $user->id);

            try {
                // Create Stripe PaymentIntent
                $paymentIntent = $this->stripe->paymentIntents->create([
                    'amount' => (int) ($media->reward * 100), // Convert to cents
                    'currency' => config('stripe.currency'),
                    'metadata' => [
                        'media_id' => $media->id,
                        'user_id' => $user->id,
                        'transaction_reference' => $transactionReference,
                    ],
                    'automatic_payment_methods' => [
                        'enabled' => true,
                    ],
                ], [
                    'idempotency_key' => $this->generateIdempotencyKey($media->id, $user->id),
                ]);

                // Create payment record
                $payment = $this->paymentRepository->create([
                    'user_id' => $user->id,
                    'media_id' => $media->id,
                    'transaction_reference' => $transactionReference,
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'status' => Payment::STATUS_PENDING,
                    'amount' => $media->reward,
                    'currency' => config('stripe.currency'),
                    'gateway_response' => $paymentIntent->toArray(),
                ]);

                $this->paymentLogger->logPaymentAttempt($payment, [
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'amount_cents' => $paymentIntent->amount,
                ]);

                return [
                    'client_secret' => $paymentIntent->client_secret,
                    'payment_id' => $payment->id,
                    'existing' => false,
                ];

            } catch (ApiErrorException $e) {
                Log::error('Stripe API error creating payment intent', [
                    'error' => $e->getMessage(),
                    'media_id' => $media->id,
                    'user_id' => $user->id,
                    'amount' => $media->reward,
                ]);
                throw new \Exception('Failed to create payment intent: ' . $e->getMessage());
            }
        });
    }

    /**
     * Confirm payment after webhook
     */
    public function confirmPayment(string $paymentIntentId): Payment
    {
        return DB::transaction(function () use ($paymentIntentId) {
            $payment = $this->paymentRepository->findByStripePaymentIntentId($paymentIntentId);
            
            if (!$payment) {
                throw new \Exception('Payment not found for payment intent: ' . $paymentIntentId);
            }

            // Get updated payment intent from Stripe
            $stripePaymentIntent = $this->stripe->paymentIntents->retrieve($paymentIntentId);
            
            if ($stripePaymentIntent->status === 'succeeded') {
                $payment->update([
                    'status' => Payment::STATUS_SUCCEEDED,
                    'paid_at' => now(),
                    'stripe_charge_id' => $stripePaymentIntent->latest_charge,
                    'gateway_response' => $stripePaymentIntent->toArray(),
                ]);

                // Update media payment status
                $payment->media->update([
                    'payment_status' => 'paid',
                    'paid_at' => now(),
                    'amount_paid' => $payment->amount,
                ]);

                $this->paymentLogger->logPaymentSuccess($payment, $stripePaymentIntent->toArray());

                return $payment;
            } else {
                throw new \Exception('Payment intent not succeeded: ' . $stripePaymentIntent->status);
            }
        });
    }

    /**
     * Handle payment failure
     */
    public function handlePaymentFailure(string $paymentIntentId, string $reason = 'Payment failed'): Payment
    {
        return DB::transaction(function () use ($paymentIntentId, $reason) {
            $payment = $this->paymentRepository->findByStripePaymentIntentId($paymentIntentId);
            
            if (!$payment) {
                throw new \Exception('Payment not found for payment intent: ' . $paymentIntentId);
            }

            $payment->update([
                'status' => Payment::STATUS_FAILED,
                'failure_reason' => $reason,
            ]);

            $this->paymentLogger->logPaymentFailure($payment, $reason);

            return $payment;
        });
    }

    /**
     * Process refund
     */
    public function processRefund(Payment $payment, float $amount = null, string $reason = 'Refund requested'): array
    {
        if (!$payment->isSuccessful()) {
            throw new \Exception('Cannot refund unsuccessful payment');
        }

        if (!$payment->stripe_charge_id) {
            throw new \Exception('No charge ID found for refund');
        }

        $refundAmount = $amount ?? $payment->amount;
        
        $this->paymentLogger->logRefundAttempt($payment, $refundAmount, $reason);

        try {
            $refund = $this->stripe->refunds->create([
                'charge' => $payment->stripe_charge_id,
                'amount' => (int) ($refundAmount * 100), // Convert to cents
                'reason' => 'requested_by_customer',
                'metadata' => [
                    'payment_id' => $payment->id,
                    'refund_reason' => $reason,
                ],
            ]);

            $payment->update([
                'status' => Payment::STATUS_REFUNDED,
                'gateway_response' => array_merge($payment->gateway_response ?? [], [
                    'refund' => $refund->toArray(),
                ]),
            ]);

            $this->paymentLogger->logRefundSuccess($payment, $refundAmount, $refund->id);

            return [
                'refund_id' => $refund->id,
                'amount' => $refundAmount,
                'status' => $refund->status,
            ];

        } catch (ApiErrorException $e) {
            Log::error('Stripe refund failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Refund failed: ' . $e->getMessage());
        }
    }

    /**
     * Get client secret for existing payment intent
     */
    private function getClientSecret(string $paymentIntentId): string
    {
        $paymentIntent = $this->stripe->paymentIntents->retrieve($paymentIntentId);
        return $paymentIntent->client_secret;
    }

    /**
     * Generate unique transaction reference
     */
    private function generateTransactionReference(string $mediaId, string $userId): string
    {
        return 'TXN_' . strtoupper(substr($mediaId, 0, 8)) . '_' . strtoupper(substr($userId, 0, 8)) . '_' . time();
    }

    /**
     * Generate idempotency key
     */
    private function generateIdempotencyKey(string $mediaId, string $userId): string
    {
        return 'payment_' . $mediaId . '_' . $userId . '_' . time();
    }

    /**
     * Validate webhook signature
     */
    public function validateWebhookSignature(string $payload, string $signature): bool
    {
        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                config('stripe.webhook_secret')
            );
            return true;
        } catch (\Exception $e) {
            $this->paymentLogger->logSecurityEvent('webhook_signature_validation_failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
} 
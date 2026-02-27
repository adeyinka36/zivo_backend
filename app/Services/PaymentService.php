<?php

namespace App\Services;

use App\Models\Media;
use App\Models\Payment;
use App\Models\Creator;
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
     * Calculate expected payment amount from media record (server-side).
     *
     * @param Media $media
     * @return array{reward_amount_cents: int, platform_fee_cents: int, total_amount_cents: int}
     */
    public function calculateExpectedPaymentAmount(Media $media): array
    {
        // Fixed cost: $5 per round (500 cents per round)
        $totalAmountCents = 500 * (int) $media->rounds;

        return [
            'reward_amount_cents' => $totalAmountCents,
            'platform_fee_cents' => 0,
            'total_amount_cents' => $totalAmountCents,
        ];
    }

    /**
     * Create a payment intent for media upload
     */
    public function createPaymentIntent(Media $media, Creator $creator): array
    {
        return DB::transaction(function () use ($media, $creator) {
            $existingPayment = $this->paymentRepository->findByMediaId($media->id)
                ->where('status', Payment::STATUS_PENDING)
                ->first();

            if ($existingPayment) {
                try {
                    $stripePaymentIntent = $this->stripe->paymentIntents->retrieve($existingPayment->stripe_payment_intent_id);
                    
                    if ($stripePaymentIntent->status === 'succeeded') {
                        throw new \Exception('Payment has already been completed for this media.');
                    }

                    return [
                        'client_secret' => $this->getClientSecret($existingPayment->stripe_payment_intent_id),
                        'payment_id' => $existingPayment->id,
                        'existing' => true,
                    ];
                } catch (ApiErrorException $e) {
                    Log::warning('Could not retrieve existing PaymentIntent, creating new one', [
                        'payment_id' => $existingPayment->id,
                        'stripe_payment_intent_id' => $existingPayment->stripe_payment_intent_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $transactionReference = $this->generateTransactionReference($media->id, $creator->id);

            try {
                // Calculate expected payment amount from Media record (server-side)
                $expectedAmounts = $this->calculateExpectedPaymentAmount($media);
                $totalAmountCents = $expectedAmounts['total_amount_cents'];
                
                $paymentIntent = $this->stripe->paymentIntents->create([
                    'amount' => (int) $totalAmountCents,
                    'currency' => config('stripe.currency'),
                    'metadata' => [
                        'media_id' => $media->id,
                        'creator_id' => $creator->id,
                        'transaction_reference' => $transactionReference,
                        'expected_amount_cents' => (string) $totalAmountCents,
                    ],
                    'automatic_payment_methods' => [
                        'enabled' => true,
                    ],
                ], [
                    'idempotency_key' => $this->generateIdempotencyKey($media->id, $creator->id),
                ]);

                // Create payment record with expected amounts
                $payment = $this->paymentRepository->create([
                    'creator_id' => $creator->id,
                    'media_id' => $media->id,
                    'transaction_reference' => $transactionReference,
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'status' => Payment::STATUS_PENDING,
                    // Store amount in major units (e.g. dollars)
                    'amount' => $totalAmountCents / 100,
                    'currency' => config('stripe.currency'),
                    'gateway_response' => array_merge($paymentIntent->toArray(), [
                        'expected_amount_cents' => $totalAmountCents,
                        'expected_reward_amount_cents' => $expectedAmounts['reward_amount_cents'],
                        'expected_platform_fee_cents' => $expectedAmounts['platform_fee_cents'],
                    ]),
                ]);

                $this->paymentLogger->logPaymentAttempt($payment, [
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'amount_cents' => $paymentIntent->amount,
                    'expected_amount_cents' => $totalAmountCents,
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
                    'creator_id' => $creator->id,
                ]);
                throw new \Exception('Failed to create payment intent: ' . $e->getMessage());
            }
        });
    }

    /**
     * Verify payment amount matches expected amount from Media record.
     *
     * @param Payment $payment
     * @param int $actualAmountCents
     * @return bool
     */
    public function verifyPaymentAmount(Payment $payment, int $actualAmountCents): bool
    {
        $media = $payment->media;
        if (!$media) {
            Log::error('Payment verification failed: Media not found', [
                'payment_id' => $payment->id,
            ]);
            return false;
        }

        $expectedAmounts = $this->calculateExpectedPaymentAmount($media);
        $expectedTotalCents = $expectedAmounts['total_amount_cents'];

        if ($actualAmountCents !== $expectedTotalCents) {
            Log::warning('Payment amount mismatch detected', [
                'payment_id' => $payment->id,
                'media_id' => $media->id,
                'creator_id' => $payment->creator_id,
                'user_id' => $payment->user_id,
                'expected_amount_cents' => $expectedTotalCents,
                'actual_amount_cents' => $actualAmountCents,
                'difference_cents' => $actualAmountCents - $expectedTotalCents,
                'media_rounds' => $media->rounds,
            ]);

            $this->paymentLogger->logSecurityEvent('payment_amount_mismatch', [
                'payment_id' => $payment->id,
                'expected_amount_cents' => $expectedTotalCents,
                'actual_amount_cents' => $actualAmountCents,
            ]);

            return false;
        }

        return true;
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
            try {
                $stripePaymentIntent = $this->stripe->paymentIntents->retrieve($paymentIntentId);
            } catch (\Exception $e) {
                throw new \Exception('Failed to retrieve payment intent from Stripe: ' . $e->getMessage());
            }
            
            if ($stripePaymentIntent->status === 'succeeded') {
                // Verify payment amount matches expected amount from Media record
                $actualAmountCents = (int) $stripePaymentIntent->amount;
                $amountMatches = $this->verifyPaymentAmount($payment, $actualAmountCents);

                if (!$amountMatches) {
                    // Log security event but don't block payment (user already paid)
                    // This allows investigation while preventing exploitation
                    Log::error('Payment amount verification failed - security alert', [
                        'payment_id' => $payment->id,
                        'payment_intent_id' => $paymentIntentId,
                        'actual_amount_cents' => $actualAmountCents,
                    ]);
                }

                $payment->update([
                    'status' => Payment::STATUS_SUCCEEDED,
                    'paid_at' => now(),
                    'stripe_charge_id' => $stripePaymentIntent->latest_charge,
                    'gateway_response' => array_merge($stripePaymentIntent->toArray(), [
                        'amount_verified' => $amountMatches,
                        'actual_amount_cents' => $actualAmountCents,
                    ]),
                ]);

                // Payment status will be updated by ProcessMediaUploadJob

                $this->paymentLogger->logPaymentSuccess($payment, array_merge($stripePaymentIntent->toArray(), [
                    'amount_verified' => $amountMatches,
                ]));

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
    private function generateTransactionReference(string $mediaId, string $creatorId): string
    {
        return 'TXN_' . strtoupper(substr($mediaId, 0, 8)) . '_' . strtoupper(substr($creatorId, 0, 8)) . '_' . time();
    }

    /**
     * Generate idempotency key
     */
    private function generateIdempotencyKey(string $mediaId, string $creatorId): string
    {
        return 'payment_' . $mediaId . '_' . $creatorId . '_' . time();
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
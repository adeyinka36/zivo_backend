<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class PaymentLogger
{
    public function logPaymentAttempt(Payment $payment, array $context = []): void
    {
        Log::info('Payment attempt initiated', [
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
            'media_id' => $payment->media_id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'transaction_reference' => $payment->transaction_reference,
            'stripe_payment_intent_id' => $payment->stripe_payment_intent_id,
            'context' => $context,
            'timestamp' => now()->toISOString(),
        ]);
    }

    public function logPaymentSuccess(Payment $payment, array $gatewayResponse = []): void
    {
        Log::info('Payment succeeded', [
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
            'media_id' => $payment->media_id,
            'amount' => $payment->amount,
            'transaction_reference' => $payment->transaction_reference,
            'stripe_payment_intent_id' => $payment->stripe_payment_intent_id,
            'stripe_charge_id' => $payment->stripe_charge_id,
            'payment_method' => $payment->payment_method,
            'gateway_response' => $gatewayResponse,
            'paid_at' => $payment->paid_at?->toISOString(),
            'timestamp' => now()->toISOString(),
        ]);
    }

    public function logPaymentFailure(Payment $payment, string $reason, array $gatewayResponse = []): void
    {
        Log::warning('Payment failed', [
            'payment_id' => $payment->id,
            'user_id' => $payment->user_id,
            'media_id' => $payment->media_id,
            'amount' => $payment->amount,
            'transaction_reference' => $payment->transaction_reference,
            'stripe_payment_intent_id' => $payment->stripe_payment_intent_id,
            'failure_reason' => $reason,
            'gateway_response' => $gatewayResponse,
            'timestamp' => now()->toISOString(),
        ]);
    }

    public function logWebhookReceived(string $eventType, array $payload): void
    {
        Log::info('Stripe webhook received', [
            'event_type' => $eventType,
            'payload_keys' => array_keys($payload),
            'timestamp' => now()->toISOString(),
        ]);
    }

    public function logWebhookProcessed(string $eventType, string $paymentIntentId, bool $success, ?string $error = null): void
    {
        $logData = [
            'event_type' => $eventType,
            'payment_intent_id' => $paymentIntentId,
            'processed_successfully' => $success,
            'timestamp' => now()->toISOString(),
        ];

        if ($error) {
            $logData['error'] = $error;
        }

        if ($success) {
            Log::info('Webhook processed successfully', $logData);
        } else {
            Log::error('Webhook processing failed', $logData);
        }
    }

    public function logRefundAttempt(Payment $payment, float $amount, string $reason): void
    {
        Log::info('Refund attempt initiated', [
            'payment_id' => $payment->id,
            'original_amount' => $payment->amount,
            'refund_amount' => $amount,
            'refund_reason' => $reason,
            'stripe_charge_id' => $payment->stripe_charge_id,
            'timestamp' => now()->toISOString(),
        ]);
    }

    public function logRefundSuccess(Payment $payment, float $amount, string $stripeRefundId): void
    {
        Log::info('Refund succeeded', [
            'payment_id' => $payment->id,
            'refund_amount' => $amount,
            'stripe_refund_id' => $stripeRefundId,
            'timestamp' => now()->toISOString(),
        ]);
    }

    public function logSecurityEvent(string $event, array $context = []): void
    {
        Log::warning('Payment security event', [
            'event' => $event,
            'context' => $context,
            'timestamp' => now()->toISOString(),
        ]);
    }
} 
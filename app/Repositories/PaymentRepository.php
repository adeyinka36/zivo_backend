<?php

namespace App\Repositories;

use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Collection;

class PaymentRepository
{
    public function create(array $data): Payment
    {
        try {
            $payment = Payment::create($data);
            
            Log::info('Payment created', [
                'payment_id' => $payment->id,
                'transaction_reference' => $payment->transaction_reference,
                'amount' => $payment->amount,
                'user_id' => $payment->user_id,
            ]);
            
            return $payment;
        } catch (\Exception $e) {
            Log::error('Failed to create payment', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            throw $e;
        }
    }

    public function findByStripePaymentIntentId(string $paymentIntentId): ?Payment
    {
        return Payment::where('stripe_payment_intent_id', $paymentIntentId)->first();
    }

    public function findByTransactionReference(string $transactionReference): ?Payment
    {
        return Payment::where('transaction_reference', $transactionReference)->first();
    }

    public function findByMediaId(string $mediaId): Collection
    {
        return Payment::where('media_id', $mediaId)->get();
    }

    public function findByUserId(string $userId): Collection
    {
        return Payment::where('user_id', $userId)->orderBy('created_at', 'desc')->get();
    }

    public function update(Payment $payment, array $data): bool
    {
        try {
            $updated = $payment->update($data);
            
            if ($updated) {
                Log::info('Payment updated', [
                    'payment_id' => $payment->id,
                    'updates' => $data,
                ]);
            }
            
            return $updated;
        } catch (\Exception $e) {
            Log::error('Failed to update payment', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            throw $e;
        }
    }

    public function getSuccessfulPaymentsByMediaId(string $mediaId): Collection
    {
        return Payment::where('media_id', $mediaId)
            ->where('status', Payment::STATUS_SUCCEEDED)
            ->get();
    }

    public function getPendingPayments(): Collection
    {
        return Payment::where('status', Payment::STATUS_PENDING)
            ->where('created_at', '<=', now()->subMinutes(30))
            ->get();
    }
} 
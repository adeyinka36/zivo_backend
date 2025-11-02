<?php

namespace App\Console\Commands;

use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Track payments for media uploads that failed after successful payment
 */
class TrackFailedMediaUploads extends Command
{
    protected $signature = 'media:track-failed-uploads';

    protected $description = 'Track successful payments where media uploads failed';

    public function handle(): int
    {
        $failedUploads = Payment::where('status', Payment::STATUS_SUCCEEDED)
            ->whereHas('media', function ($query) {
                $query->where('active', false);
            })
            ->with('media:id,name,active')
            ->get();

        if ($failedUploads->isEmpty()) {
            $this->info('No failed media uploads found.');
            return self::SUCCESS;
        }

        $this->info("Found {$failedUploads->count()} failed media uploads with successful payments.");

        foreach ($failedUploads as $payment) {
            Log::warning('Failed media upload with successful payment', [
                'payment_id' => $payment->id,
                'media_id' => $payment->media->id,
                'media_name' => $payment->media->name,
                'payment_amount' => $payment->amount,
                'payment_created_at' => $payment->created_at,
            ]);

            $this->line("Payment ID: {$payment->id} | Media: {$payment->media->name}");
        }

        return self::SUCCESS;
    }
}

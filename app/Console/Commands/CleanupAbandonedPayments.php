<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CleanupAbandonedPayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:cleanup-abandoned {--hours=24 : Hours after which to consider payments abandoned}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up abandoned payments and their associated media records';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $hours = $this->option('hours');
        $cutoffTime = Carbon::now()->subHours($hours);

        $this->info("Cleaning up payments older than {$hours} hours...");

        $abandonedPayments = Payment::where('status', Payment::STATUS_PENDING)
            ->where('created_at', '<', $cutoffTime)
            ->with('media')
            ->get();

        $count = 0;

        foreach ($abandonedPayments as $payment) {
            DB::transaction(function () use ($payment, &$count) {
                // Delete the payment
                $payment->delete();

                // Delete associated media if it exists and is pending
                if ($payment->media && $payment->media->payment_status === 'pending') {
                    $payment->media->delete();
                }

                $count++;
            });
        }

        $this->info("Cleaned up {$count} abandoned payments.");

        // Also clean up orphaned pending media records
        $orphanedMedia = Media::where('payment_status', 'pending')
            ->where('created_at', '<', $cutoffTime)
            ->whereDoesntHave('payments')
            ->get();

        $orphanedCount = 0;
        foreach ($orphanedMedia as $media) {
            $media->delete();
            $orphanedCount++;
        }

        $this->info("Cleaned up {$orphanedCount} orphaned media records.");

        return 0;
    }
} 
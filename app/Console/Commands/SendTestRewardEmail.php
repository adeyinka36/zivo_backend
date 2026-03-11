<?php

namespace App\Console\Commands;

use App\Models\Creator;
use App\Models\Media;
use App\Models\User;
use App\Notifications\RewardEmailNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendTestRewardEmail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Usage:
     *  php artisan reward:test-email user@example.com
     */
    protected $signature = 'reward:test-email {email? : User email to send the test reward to}';

    /**
     * The console command description.
     */
    protected $description = 'Send a test reward email with default values for all fields.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');

        /** @var User|null $user */
        $user = $email
            ? User::where('email', $email)->first()
            : User::first();

        if (!$user) {
            $this->error('No user found to send the test reward email to.');
            return self::FAILURE;
        }

        /** @var Creator|null $creator */
        $creator = Creator::first();

        if (!$creator) {
            $this->error('No creator found. Please create at least one creator to test the reward email.');
            return self::FAILURE;
        }

        $now = Carbon::now(config('app.timezone'));

        // Create an in-memory media instance with sensible default values.
        $media = new Media([
            'description' => 'Exclusive in-store reward just for you',
            'offer' => '10% off your next purchase',
            'from_date' => $now->copy()->startOfDay(),
            'to_date' => $now->copy()->addWeek()->endOfDay(),
        ]);

        // Attach the creator relation so the notification can read name + address.
        $media->setRelation('creator', $creator);

        $defaultCode = 'ZI-VO-TESTCODE';

        $this->info("Sending test reward email to {$user->email}...");

        $user->notify(new RewardEmailNotification($media, $defaultCode));

        $this->info('Test reward email dispatched. Check your mail inbox (or mailhog/test mailer).');

        return self::SUCCESS;
    }
}


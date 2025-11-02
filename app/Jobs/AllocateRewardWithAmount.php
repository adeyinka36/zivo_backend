<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\RewardAllocationSuccessNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class AllocateRewardWithAmount implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly User $winner,
        private readonly int $winningAmount
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $this->sendAmazonGiftCard($this->winner, $this->winningAmount);
        } catch (ConnectionException|RequestException $e) {
            Log::error('Failed to send Amazon gift card', [
                'user_id' => $this->winner->id,
                'amount' => $this->winningAmount,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    private function sendAmazonGiftCard(User $winner, int $winningAmount): void
    {
        $apiKey = config('services.tremendous.api_key');
        $endpoint = config('services.tremendous.api_endpoint').'/orders';

        $payload = [
            'external_id' => (string) Str::uuid(),
            'payment' => [
                'funding_source_id' => 'balance',
            ],
            'reward' => [
                'value' => [
                    'denomination' => $winningAmount / 100,
                    'currency_code' => 'USD',
                ],
                'delivery' => [
                    'method' => 'EMAIL',
                ],
                'recipient' => [
                    'name'  => $winner->name,
                    'email' => $winner->email,
                ],
                'products' => [
                    'OKMHM2X2OHYV',
                    'Q24BD9EZ332JT',
                    'O7VZ5WQOCUQM'
                ],
            ],
        ];

        $response = Http::withToken($apiKey)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->asJson()
            ->post($endpoint, $payload);

        $response->throw();

        $rewardAmountDollars = $winningAmount / 100;
        $winner->notify(new RewardAllocationSuccessNotification($rewardAmountDollars));
    }
}

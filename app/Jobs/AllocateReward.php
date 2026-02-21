<?php

namespace App\Jobs;

use App\Models\Media;
use App\Models\User;
use App\Notifications\RewardAllocationSuccessNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class AllocateReward implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(private readonly Media $media, private readonly User $winner)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $this->sendAmazonGiftCard($this->winner);
        } catch (ConnectionException|RequestException $e) {
            Log::error('Failed to send Amazon gift card:-----> ' . $e->getMessage());
        }
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function sendAmazonGiftCard(User $winner)
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
                    'denomination'  => ($this->media->reward) /100,
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
                    'OKMHM2X2OHYV',  //AMAZON USA
                    'Q24BD9EZ332JT', //VIRTUAL VISA
                    'O7VZ5WQOCUQM'  //PAYPAL
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

//        $rewardAmountDollars = $this->media->reward / 100;
//        $winner->notify(new RewardAllocationSuccessNotification($rewardAmountDollars));

        return $response->json();
    }

}

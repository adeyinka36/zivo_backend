<?php

namespace App\Jobs;

use App\Models\Media;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class AllocateReward implements ShouldQueue
{
    use Queueable;

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

        }
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function sendAmazonGiftCard(User $winner)
    {
        $apiKey = config('services.tremendous.api_key');
        $endpoint = config('services.tremendous.api_key').'/orders';

        $payload = [
            'external_id' => (string) Str::uuid(),
            'payment' => [
                'funding_source_id' => 'balance',
            ],
            'reward' => [
                'value' => [
                    'denomination'  => 25,
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
                ],
            ],
        ];

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->post($endpoint, $payload);

        $response->throw();

        return $response->json();
    }

}

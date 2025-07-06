<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
//use YieldStudio\LaravelExpoNotifier\ExpoNotificationsChannel;
//use YieldStudio\LaravelExpoNotifier\Dto\ExpoMessage;
use Illuminate\Support\Facades\Http;

class SendNotification extends Notification
{
//    public function via($notifiable): array
//    {
//        return [ExpoNotificationsChannel::class];
//    }

    /**
     * @throws ConnectionException
     */
    public static function toExpoNotification(array $playerTokens, string $title, string $body, array $data): void
    {
        $payload = [
            'to' => $playerTokens,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ];

        try {
             Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post('https://exp.host/--/api/v2/push/send', $payload);
        }catch (ConnectionException $e) {
            Log::error('Failed to send Expo notification---> ' . $e->getMessage());
            throw $e;
        }
    }


    public static function sendDirectExpoNotification(string $token, string $title, string $body): array
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->post('https://exp.host/--/api/v2/push/send', [
            'to' => $token,
            'title' => $title,
            'body' => $body,
        ]);

        Log::info('Sent direct Expo notification to: ' . $token);
        Log::info('Expo response: ' . $response->body());

        return $response->json();
    }
}

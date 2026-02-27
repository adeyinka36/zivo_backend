<?php

namespace App\Services;

use App\Models\User;
use App\Models\Media;
use App\Models\UserAssignment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Service for handling push notifications via Expo Push API
 */
class NotificationService
{
    private const EXPO_API_URL = 'https://exp.host/--/api/v2/push/send';
    private const REQUEST_TIMEOUT = 30;

    /**
     * Send notification to subscribed users who have been assigned to this media.
     */
    public function sendNotificationOfUploadedMediaToSubscribed(User $uploader, Media $media): bool
    {
        $assignedUserIds = UserAssignment::where('media_id', $media->id)
            ->pluck('user_id')
            ->unique()
            ->toArray();

        if (empty($assignedUserIds)) {
            Log::info('No assigned users found for new media notification', [
                'media_id' => $media->id
            ]);
            return true;
        }

        $assignedUsers = User::whereIn('id', $assignedUserIds)
            ->where('push_notifications', true)
            ->whereNotNull('push_token')
            ->where('id', '!=', $uploader->id)
            ->get()
            ->unique('push_token')
            ->values();

        if ($assignedUsers->isEmpty()) {
            Log::info('No subscribed assigned users found for new media notification', [
                'media_id' => $media->id,
                'assigned_user_count' => count($assignedUserIds)
            ]);
            return true;
        }

        $validUsers = $assignedUsers->filter(function ($user) {
            return $this->canSendNotification($user);
        });

        if ($validUsers->isEmpty()) {
            Log::info('No valid push tokens found for assigned users', [
                'media_id' => $media->id
            ]);
            return true;
        }

        $messages = $validUsers->map(function ($user) use ($media, $uploader) {
            return [
                'to' => $user->push_token,
                'title' => '🎬 New Media Uploaded!',
                'body' => 'New media uploaded',
                'data' => [
                    'type' => 'new_media_upload',
                    'mediaId' => $media->id,
                    'mediaName' => Str::limit($media->description, 15, '...'),
                    'uploaderName' => $uploader->name,
                    'screen' => 'explore'
                ],
                'sound' => 'default',
                'badge' => 1
            ];
        })->toArray();

        Log::info('Sending new media notifications to assigned users', [
            'media_id' => $media->id,
            'recipient_count' => count($messages)
        ]);

        return $this->sendBatchToExpo($messages, $media->id, $uploader->id);
    }

    /**
     * Send media upload success notification to user
     */
    public function sendMediaUploadSuccess(User $user, Media $media): bool
    {
        if (!$this->canSendNotification($user)) {
            return false;
        }

        $payload = [
            'to' => $user->push_token,
            'title' => '🎉 Media Upload Complete!',
            'body' => "Your media is now live! Details have been sent to {$user->email}",
            'data' => [
                'type' => 'media_upload_success',
                'mediaId' => $media->id,
                'mediaName' => Str::limit($media->description ?? $media->name, 15, '...'),
                'screen' => 'explore'
            ],
            'sound' => 'default',
            'badge' => 1
        ];

        return $this->sendToExpo($payload, $user->id);
    }

    /**
     * Send email verification success notification to user
     */
    public function sendEmailVerificationSuccess(User $user): bool
    {
        if (!$this->canSendNotification($user)) {
            return false;
        }

        $payload = [
            'to' => $user->push_token,
            'title' => '✅ Email Verified!',
            'body' => 'Your email has been successfully verified',
            'data' => [
                'type' => 'email_verification_success',
                'userId' => $user->id,
                'emailVerifiedAt' => $user->email_verified_at?->toISOString(),
                'screen' => 'explore'
            ],
            'sound' => 'default',
            'badge' => 1
        ];

        return $this->sendToExpo($payload, $user->id);
    }

    /**
     * Send notification when media becomes current for user
     */
    public function sendCurrentMediaAssigned(User $user, Media $media, ?\App\Models\UserAssignment $assignment = null): bool
    {
        if (!$this->canSendNotification($user)) {
            return false;
        }

        $expirationTime = null;
        $expirationText = '';
        
        if ($assignment && $assignment->created_at) {
            $expirationTime = $assignment->created_at->copy()->addHours(24);
        } else {
            $expirationTime = now()->addHours(24);
        }
        
        $expirationText = $this->formatExpirationTime($expirationTime);

        $body = 'You have new media to watch. PLEASE WATCH BEFORE ' . $expirationText . '. You may need to refresh the home screen to see it.';

        $payload = [
            'to' => $user->push_token,
            'title' => '🎬 New Media Available!',
            'body' => $body,
            'data' => [
                'type' => 'current_media_assigned',
                'mediaId' => $media->id,
                'mediaName' => Str::limit($media->description ?? 'Media', 15, '...'),
                'expiresAt' => $expirationTime ? $expirationTime->toIso8601String() : null,
                'screen' => 'explore'
            ],
            'sound' => 'default',
            'badge' => 1
        ];

        return $this->sendToExpo($payload, $user->id);
    }

    /**
     * Format expiration time in a human-readable way
     */
    private function formatExpirationTime($expirationTime): string
    {
        if (!$expirationTime) {
            return '';
        }

        $expiration = $expirationTime instanceof \Carbon\Carbon ? $expirationTime : \Carbon\Carbon::parse($expirationTime);
        
        return $expiration->format('M j, Y \a\t g:i A');
    }

    /**
     * Send notification when current media is removed for user
     */
    public function sendCurrentMediaRemoved(User $user, Media $media): bool
    {
        if (!$this->canSendNotification($user)) {
            return false;
        }

        $payload = [
            'to' => $user->push_token,
            'title' => 'Media No Longer Available',
            'body' => 'Your assigned media is no longer available',
            'data' => [
                'type' => 'current_media_removed',
                'mediaId' => $media->id,
                'mediaName' => Str::limit($media->description ?? 'Media', 15, '...'),
                'screen' => 'explore'
            ],
            'sound' => 'default',
            'badge' => 1
        ];

        return $this->sendToExpo($payload, $user->id);
    }

    /**
     * Send campaign completion notification to media uploader
     */
    public function sendCampaignCompletionNotification(User $user, Media $media): bool
    {
        if (!$this->canSendNotification($user)) {
            return false;
        }

        $targetViews = (int) config('services.media.target_views', 25);
        $totalViews = $media->rounds * $targetViews;
        $mediaName = $media->name ?: 'Your media';
        $mediaDescription = $media->description ?: 'No description';

        $body = sprintf(
            'Your campaign "%s" completed successfully! Total views: %s',
            Str::limit($mediaName, 20, '...'),
            number_format($totalViews)
        );

        $payload = [
            'to' => $user->push_token,
            'title' => '🎉 Campaign Completed!',
            'body' => $body,
            'data' => [
                'type' => 'campaign_completed',
                'mediaId' => $media->id,
                'mediaName' => Str::limit($mediaName, 15, '...'),
                'mediaDescription' => Str::limit($mediaDescription, 30, '...'),
                'rounds' => $media->rounds,
                'totalViews' => $totalViews,
                'screen' => 'profile'
            ],
            'sound' => 'default',
            'badge' => 1
        ];

        return $this->sendToExpo($payload, $user->id);
    }

    /**
     * Check if notification can be sent to user
     */
    private function canSendNotification(User $user): bool
    {
        return $user->push_notifications 
            && !empty($user->push_token)
            && str_starts_with($user->push_token, 'ExponentPushToken[')
            && $user->email_verified_at !== null;
    }

    /**
     * Send notification payload to Expo Push API
     */
    private function sendToExpo(array $payload, string $userId): bool
    {
        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Accept-Encoding' => 'gzip, deflate'
                ])
                ->post(self::EXPO_API_URL, $payload);

            if ($response->successful()) {
                $this->logSuccess($userId, $response->json());
                return true;
            }

            $this->logFailure($userId, $response->status(), $response->body());
            return false;

        } catch (Throwable $e) {
            $this->logException($userId, $e);
            return false;
        }
    }

    /**
     * Send batch notifications to Expo Push API
     */
    private function sendBatchToExpo(array $messages, string $mediaId, string $uploaderId): bool
    {
        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Accept-Encoding' => 'gzip, deflate'
                ])
                ->post(self::EXPO_API_URL, $messages);

            if ($response->successful()) {
                $responseData = $response->json();
                $this->logBatchSuccess($mediaId, $uploaderId, $responseData, count($messages));
                return true;
            }

            $this->logBatchFailure($mediaId, $uploaderId, $response->status(), $response->body());
            return false;

        } catch (Throwable $e) {
            $this->logBatchException($mediaId, $uploaderId, $e);
            return false;
        }
    }

    /**
     * Log successful notification delivery
     */
    private function logSuccess(string $userId, array $response): void
    {
        Log::info('Push notification sent successfully', [
            'user_id' => $userId,
            'expo_response' => $response
        ]);
    }

    /**
     * Log notification delivery failure
     */
    private function logFailure(string $userId, int $status, string $body): void
    {
        Log::warning('Push notification failed', [
            'user_id' => $userId,
            'status' => $status,
            'response_body' => $body
        ]);
    }

    /**
     * Log notification exception
     */
    private function logException(string $userId, Throwable $e): void
    {
        Log::error('Push notification exception', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    /**
     * Log successful batch notification delivery
     */
    private function logBatchSuccess(string $mediaId, string $uploaderId, array $response, int $messageCount): void
    {
        Log::info('Batch push notification sent successfully', [
            'media_id' => $mediaId,
            'uploader_id' => $uploaderId,
            'message_count' => $messageCount,
            'expo_response' => $response
        ]);
    }

    /**
     * Log batch notification delivery failure
     */
    private function logBatchFailure(string $mediaId, string $uploaderId, int $status, string $body): void
    {
        Log::warning('Batch push notification failed', [
            'media_id' => $mediaId,
            'uploader_id' => $uploaderId,
            'status' => $status,
            'response_body' => $body
        ]);
    }

    /**
     * Log batch notification exception
     */
    private function logBatchException(string $mediaId, string $uploaderId, Throwable $e): void
    {
        Log::error('Batch push notification exception', [
            'media_id' => $mediaId,
            'uploader_id' => $uploaderId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class PlatformController extends Controller
{
    /**
     * Get platform information including about video URL and description.
     *
     * @return JsonResponse
     */
    public function getPlatformInfo(): JsonResponse
    {
        $cacheKey = 'platform_info';
        $cacheDuration = 3600; // 1 hour

        $data = Cache::remember($cacheKey, $cacheDuration, function () {
            $aboutVideoUrl = $this->resolveVideoUrl(
                'platform.about_video_url',
                config('platform.about_video_path', 'platform/about-video.mp4'),
                config('platform.about_video_disk', 'public'),
                'about'
            );
            $creatorVideoUrl = $this->resolveVideoUrl(
                'platform.about_creator_video_url',
                config('platform.about_creator_video_path', 'platform/about-creator-video.mp4'),
                config('platform.about_creator_video_disk', 'public'),
                'creator'
            );

            $mediaConfig = config('services.media', []);
            $stripeConfig = config('stripe', []);

            return [
                'video_url' => $aboutVideoUrl,
                'creator_video_url' => $creatorVideoUrl,
                'description' => config('platform.about_description', 'Welcome to Zivo! Pay Attention.'),
                'creator_description' => config('platform.about_creator_description', 'Learn how to create and share your content on Zivo. Upload videos, set rewards, and get attention.'),
                'media' => [
                    'target_views' => (int) ($mediaConfig['target_views'] ?? 10),
                    'cost_per_round' => (float) ($mediaConfig['cost_per_round'] ?? 0.25),
                    'min_rounds' => (int) ($mediaConfig['min_rounds'] ?? 1),
                    'max_rounds' => (int) ($mediaConfig['max_rounds'] ?? 1000000),
                    'min_campaign_days' => (int) ($mediaConfig['min_campaign_days'] ?? 1),
                    'max_campaign_days' => (int) ($mediaConfig['max_campaign_days'] ?? 365),
                    'max_upload_size_mb' => (int) ($mediaConfig['max_upload_size_mb'] ?? 500),
                    'allowed_mime_types' => $mediaConfig['allowed_mime_types'] ?? [],
                ],
                'payments' => [
                    'currency' => $stripeConfig['currency'] ?? 'usd',
                    'stripe_publishable_key' => $stripeConfig['publishable_key'] ?? null,
                ],
                'platform' => [
                    'name' => config('platform.platform_name', 'Zivo'),
                    'support_email' => config('platform.support_email', 'support@example.com'),
                    'support_url' => config('platform.support_url', 'https://zivo.app/support'),
                ],
            ];
        });

        return response()->json($data);
    }

    /**
     * Get media target views configuration value.
     * This value is cached as it rarely changes.
     *
     * @return JsonResponse
     */
    public function getTargetViews(): JsonResponse
    {
        $cacheKey = 'media_target_views';
        $cacheDuration = 86400; // 24 hours

        $targetViews = Cache::remember($cacheKey, $cacheDuration, function () {
            return (int) config('services.media.target_views', 25);
        });

        return response()->json([
            'target_views' => $targetViews,
        ]);
    }

    /**
     * Resolve video URL: use env URL if set and non-empty, otherwise build from storage path/disk.
     *
     * @param string $urlConfigKey Config key for optional full URL (e.g. platform.about_video_url)
     * @param string $path
     * @param string $disk
     * @param string $type
     * @return string|null
     */
    private function resolveVideoUrl(string $urlConfigKey, string $path, string $disk, string $type): ?string
    {
        $url = config($urlConfigKey);
        if ($url !== null && $url !== '') {
            return $url;
        }
        return $this->getVideoUrl($path, $disk, $type);
    }

    /**
     * Get video URL for a given path and disk.
     *
     * @param string $videoPath
     * @param string $disk
     * @param string $type
     * @return string|null
     */
    private function getVideoUrl(string $videoPath, string $disk, string $type): ?string
    {
        try {
            $storage = Storage::disk($disk);
            
            if (!$storage->exists($videoPath)) {
                return null;
            }
            
            if ($disk === 's3') {
                return $storage->temporaryUrl($videoPath, now()->addHours(24));
            } else {
                return $storage->url($videoPath);
            }
        } catch (\Exception $e) {
            Log::error("Failed to generate platform {$type} video URL", [
                'error' => $e->getMessage(),
                'path' => $videoPath,
                'disk' => $disk
            ]);
        }

        return null;
    }
}

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
            $aboutVideoPath = config('platform.about_video_path', 'platform/about-video.mp4');
            $aboutVideoDisk = config('platform.about_video_disk', 'public');
            $creatorVideoPath = config('platform.about_creator_video_path', 'platform/about-creator-video.mp4');
            $creatorVideoDisk = config('platform.about_creator_video_disk', 'public');
            
            $aboutVideoUrl = $this->getVideoUrl($aboutVideoPath, $aboutVideoDisk, 'about');
            $creatorVideoUrl = $this->getVideoUrl($creatorVideoPath, $creatorVideoDisk, 'creator');

            return [
                'video_url' => $aboutVideoUrl,
                'creator_video_url' => $creatorVideoUrl,
                'description' => config('platform.about_description', 'Welcome to Zivo! A platform where content creators can share their media and viewers can discover new content. Watch videos, answer quizzes, and earn rewards.'),
                'creator_description' => config('platform.about_creator_description', 'Learn how to create and share your content on Zivo. Upload videos, set rewards, and reach your audience.'),
            ];
        });

        return response()->json($data);
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

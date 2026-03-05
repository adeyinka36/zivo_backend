<?php

return [
    // Optional full URLs (e.g. from CDN). When set, used instead of path/disk.
    'about_video_url' => env('PLATFORM_ABOUT_VIDEO_URL'),
    'about_creator_video_url' => env('PLATFORM_ABOUT_CREATOR_VIDEO_URL'),
    'about_video_path' => env('PLATFORM_ABOUT_VIDEO_PATH', 'platform/about-video.mp4'),
    'about_video_disk' => env('PLATFORM_ABOUT_VIDEO_DISK', 'public'),
    'about_description' => env('PLATFORM_ABOUT_DESCRIPTION', 'Welcome! ZI-VO is a platform where content creators can share their media and viewers can discover new content. Watch videos, answer quizzes, and earn rewards.'),
    'about_creator_video_path' => env('PLATFORM_ABOUT_CREATOR_VIDEO_PATH', 'platform/about-creator-video.mp4'),
    'about_creator_video_disk' => env('PLATFORM_ABOUT_CREATOR_VIDEO_DISK', 'public'),
    'about_creator_description' => env('PLATFORM_ABOUT_CREATOR_DESCRIPTION', 'Learn how to create and share your content on Zivo. Upload videos, set rewards, and reach your audience.'),

    // General platform metadata exposed (safely) via /platform-info
    'platform_name' => env('PLATFORM_NAME', 'Zivo'),
    // CONTACT_EMAIL is shown on login/register; falls back to PLATFORM_SUPPORT_EMAIL
    'support_email' => env('CONTACT_EMAIL', env('PLATFORM_SUPPORT_EMAIL', 'support@example.com')),
    'support_url' => env('PLATFORM_SUPPORT_URL', 'https://zivo.app/support'),
];

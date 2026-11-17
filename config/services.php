<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', env('GOOGLE_OAUTH_WEB_CLIENT_ID')),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
        'frontend_redirect' => env('GOOGLE_FRONTEND_REDIRECT_URI', 'zivo-app://auth/callback'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID', 'com.adegiwa.zivo.app'),
    ],

    'tremendous' => [
        'api_key' => env('TREMENDOUUS_API_KEY'),
        'api_endpoint' => env('TREMENDOUUS_API_ENDPOINT'),
    ],

    'media' => [
        // How many views are required to complete a single round.
        'target_views' => (int) env('MEDIA_TARGET_VIEWS', 10),

        // Monetary cost per round in your main currency (e.g. USD). Set to 0 to disable charging.
        'cost_per_round' => (float) env('MEDIA_COST_PER_ROUND', 0.25),

        // Minimum and maximum rounds allowed when creators configure a campaign.
        'min_rounds' => (int) env('MEDIA_MIN_ROUNDS', 1),
        'max_rounds' => (int) env('MEDIA_MAX_ROUNDS', 1000000),

        // Minimum and maximum number of days a campaign can run.
        'min_campaign_days' => (int) env('MEDIA_MIN_CAMPAIGN_DAYS', 1),
        'max_campaign_days' => (int) env('MEDIA_MAX_CAMPAIGN_DAYS', 365),

        // Maximum upload size (in megabytes) for media files.
        'max_upload_size_mb' => (int) env('MEDIA_MAX_UPLOAD_SIZE_MB', 500),

        // Comma-separated list of allowed MIME types for media uploads.
        'allowed_mime_types' => explode(
            ',',
            env('MEDIA_ALLOWED_MIME_TYPES', 'video/mp4,video/quicktime,image/jpeg,image/png')
        ),
    ],

    'companies_house' => [
        'api_key' => env('COMPANIES_HOUSE_API_KEY'),
        'base_url' => env('COMPANIES_HOUSE_BASE_URL', 'https://api.company-information.service.gov.uk'),
    ],

];

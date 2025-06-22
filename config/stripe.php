<?php

return [
    'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
    'secret_key' => env('STRIPE_SECRET_KEY'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    'currency' => env('STRIPE_CURRENCY', 'usd'),
    'merchant_identifier' => env('STRIPE_MERCHANT_IDENTIFIER', 'merchant.com.zivo.app'),
    
    // Payment method types
    'payment_methods' => [
        'card',
        'apple_pay',
        'google_pay',
    ],
    
    // Webhook events to handle
    'webhook_events' => [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
        'charge.refunded',
    ],
    
    // Retry configuration
    'max_retries' => 3,
    'retry_delay' => 5, // seconds
]; 
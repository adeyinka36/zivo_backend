<?php

namespace App\Providers;

use App\Repositories\PaymentRepository;
use App\Services\PaymentLogger;
use App\Services\PaymentService;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class StripeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StripeClient::class, function ($app) {
            return new StripeClient(config('stripe.secret_key'));
        });

        $this->app->singleton(PaymentRepository::class);
        $this->app->singleton(PaymentLogger::class);
        $this->app->singleton(PaymentService::class);
    }

    public function boot(): void
    {
        // Set Stripe API key globally
        \Stripe\Stripe::setApiKey(config('stripe.secret_key'));
    }
} 
<?php

namespace App\Providers;

use App\Repositories\Contracts\AuthRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\AuthRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(AuthRepositoryInterface::class, AuthRepository::class);
    }

    public function boot(): void
    {
        //
    }
}
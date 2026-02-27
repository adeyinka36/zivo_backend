<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'ensure.creator' => \App\Http\Middleware\EnsureCreator::class,
            'ensure.user' => \App\Http\Middleware\EnsureUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\App\Exceptions\Auth\InvalidCredentialsException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode());
        });
        
        $exceptions->render(function (\App\Exceptions\Auth\RegistrationFailedException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode());
        });
    })->create();

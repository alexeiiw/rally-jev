<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel's standard web middleware includes sessions and CSRF protection.
    })
    ->withExceptions(function ($exceptions): void {
        // Use Laravel's default exception handling.
    })
    ->create();

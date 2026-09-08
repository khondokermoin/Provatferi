<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => \App\Http\Middleware\EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

// On shared hosting without vhost-level docroot control, the deployed public/
// lives in a separate directory (the subdomain's document root) instead of
// alongside this app. env() is useless here — this file runs before Laravel
// loads .env — so the path comes from the front controller, which physically
// sits in the docroot, or from a real process env var for CLI.
if (defined('LARAVEL_PUBLIC_PATH_OVERRIDE')) {
    $app->usePublicPath(LARAVEL_PUBLIC_PATH_OVERRIDE);
} elseif ($publicPath = getenv('LARAVEL_PUBLIC_PATH')) {
    $app->usePublicPath($publicPath);
}

return $app;

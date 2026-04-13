<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Process scheduled notifications every 5 minutes
        $schedule->command('notifications:process-scheduled')
            ->everyFiveMinutes()
            ->description('Process scheduled notifications that are due to be sent');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
        ]);
        
        // Register CORS middleware at the beginning to run first
        $middleware->prepend(\App\Http\Middleware\CorsMiddleware::class);
        
        // Disable default CORS handling if any
        $middleware->remove(\Illuminate\Http\Middleware\HandleCors::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Symfony\Component\Routing\Exception\RouteNotFoundException $e) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        });
    })->create();

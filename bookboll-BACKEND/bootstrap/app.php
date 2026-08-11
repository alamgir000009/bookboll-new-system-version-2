<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__ . '/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'checkRoles' => \App\Http\Middleware\CheckRole::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'stripe/payment-webhook',
            'broadcasting/auth',
            'api/broadcasting/auth',
        ]);

        // $middleware->api(prepend: [
        //     \App\Http\Middleware\RestrictApiOrigin::class,
        // ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Exception $e) {
            try {
                \Illuminate\Support\Facades\Mail::to('alamgir000009@gmail.com')
                    ->send(new \App\Mail\ExceptionNotificationEmail($e, [
                        'user_id' => auth()->id(),
                        'request_data' => request()->all(),
                    ]));
            } catch (\Exception $mailException) {
                \Illuminate\Support\Facades\Log::error('Failed to send exception notification email: ' . $mailException->getMessage());
            }
        });
    })->create();

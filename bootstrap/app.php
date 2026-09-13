<?php

use App\Http\Middleware\AdminAuthorize;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\AttachRequestId;
use App\Http\Middleware\AuthPanelMiddleware;
use App\Http\Middleware\RedirectIfAdmin;
use App\Http\Middleware\RestrictIpPanelAccess;
use App\Http\Middleware\ScopeTenant;
use App\Http\Middleware\SetLocale;
use App\Providers\ModuleServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
            AttachRequestId::class,
        ]);

        $middleware->api(append: [
            AttachRequestId::class,
        ]);

        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'admin.can' => AdminAuthorize::class,
            'admin.guest' => RedirectIfAdmin::class,
            'auth.panel' => AuthPanelMiddleware::class,
            'panel.ip' => RestrictIpPanelAccess::class,
            'tenant' => ScopeTenant::class,
        ]);
        $middleware->redirectUsersTo(fn () => route('panel.dashboard'));
        $middleware->redirectGuestsTo(fn () => route('panel.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Production-only last-resort boundary: unexpected exceptions render
        // the branded 500 page. HttpExceptions (403/404/405/419/503/...),
        // validation failures (normal redirect-with-errors flows such as a
        // failed login), and api/* requests keep Laravel's stock rendering.
        $exceptions->renderable(function (Throwable $exception, Request $request) {
            if (! app()->environment('production')
                || $exception instanceof HttpExceptionInterface
                || $exception instanceof ValidationException
                || $request->is('api/*')) {
                return null;
            }

            return response()->view('errors.500', [
                'requestId' => $request->attributes->get('request_id'),
            ], 500);
        });
    })
    ->withProviders([
        ModuleServiceProvider::class,
    ])->create();

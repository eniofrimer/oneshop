<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Lunar\Exceptions\Carts\CartException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([CartException::class]);
        $exceptions->render(function (CartException $exception, Request $request) {
            if ($request->routeIs('store.*')) {
                return $request->expectsJson()
                    ? response()->json(['message' => $exception->getMessage(), 'errors' => ['cart' => [$exception->getMessage()]]], 422)
                    : back()->withErrors(['cart' => $exception->getMessage()])->withInput($request->except('_token'));
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

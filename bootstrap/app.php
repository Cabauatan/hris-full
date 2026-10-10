<?php

use App\Http\Middleware\AuthenticateAccountSession;
use App\Http\Middleware\EnsureApiResponseSecurity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(EnsureApiResponseSecurity::class);
        $middleware->alias(['auth.session' => AuthenticateAccountSession::class]);
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(fn (Response $response, Throwable $exception, Request $request): Response => app(EnsureApiResponseSecurity::class)->secureResponse($request, $response));

        $exceptions->render(function (RuntimeException $exception, Request $request): ?JsonResponse {
            if ($exception::class === RuntimeException::class
                && $request->attributes->get('api_business_errors') === true) {
                return response()->json(['message' => $exception->getMessage()], 409);
            }

            return null;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

<?php

use App\Git\Exceptions\GitProviderException;
use App\Git\Exceptions\InvalidResponseException;
use App\Git\Exceptions\RateLimitException;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['theme']);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (GitProviderException $exception, Request $request) {
            if (! $request->is('api/git-sources')) {
                return null;
            }

            if ($exception instanceof RateLimitException) {
                $headers = $exception->retryAt === null ? [] : [
                    'Retry-After' => (string) max(0, $exception->retryAt->getTimestamp() - now()->getTimestamp()),
                ];

                return response()->json([
                    'code' => 'errors.rateLimited',
                    'retry_at' => $exception->retryAt?->format(DATE_ATOM),
                ], 429, $headers);
            }

            return response()->json([
                'code' => $exception instanceof InvalidResponseException
                    ? 'errors.providerInvalidResponse'
                    : 'errors.providerUnavailable',
            ], $exception instanceof InvalidResponseException ? 502 : 503);
        });

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($request->is('api/git-sources') && $response->getStatusCode() >= 500
                && ! $exception instanceof GitProviderException) {
                return response()->json(['code' => 'errors.unexpected'], 500);
            }

            return $response;
        });
    })->create();

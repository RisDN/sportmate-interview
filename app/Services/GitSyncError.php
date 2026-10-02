<?php

namespace App\Services;

use App\Git\Exceptions\AccessDeniedException;
use App\Git\Exceptions\AuthenticationException;
use App\Git\Exceptions\GitProviderException;
use App\Git\Exceptions\InvalidResponseException;
use App\Git\Exceptions\RateLimitException;
use App\Git\Exceptions\SourceNotFoundException;
use Throwable;

final class GitSyncError
{
    public static function code(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof RateLimitException => 'errors.rateLimited',
            $exception instanceof AuthenticationException => 'errors.providerAuthenticationFailed',
            $exception instanceof AccessDeniedException => 'errors.providerAccessDenied',
            $exception instanceof SourceNotFoundException => 'errors.sourceNotFound',
            $exception instanceof InvalidResponseException => 'errors.providerInvalidResponse',
            $exception instanceof GitProviderException => 'errors.providerUnavailable',
            default => 'errors.unexpected',
        };
    }

    public static function isTransient(Throwable $exception): bool
    {
        return $exception instanceof GitProviderException
            && ! $exception instanceof InvalidResponseException
            && ! $exception instanceof RateLimitException
            && ($exception->statusCode === null || $exception->statusCode === 408 || $exception->statusCode >= 500);
    }

    /** @return array<string, int|string|null> */
    public static function context(Throwable $exception): array
    {
        // Request objects, headers and exception messages may contain credentials.
        return [
            'exception_class' => $exception::class,
            'error_code' => self::code($exception),
            'status_code' => $exception instanceof GitProviderException ? $exception->statusCode : null,
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];
    }
}

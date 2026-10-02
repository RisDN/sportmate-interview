<?php

namespace App\Git\Exceptions;

use DateTimeImmutable;

final class RateLimitException extends GitProviderException
{
    public function __construct(
        string $message,
        public readonly ?DateTimeImmutable $retryAt = null,
        ?int $statusCode = null,
    ) {
        parent::__construct($message, $statusCode);
    }
}

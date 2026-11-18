<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

final class PixelforgeException extends RuntimeException
{
    /** Errors that retrying the same request can't fix. */
    private const array PERMANENT = [
        'invalid_api_key',
        'validation_failed',
        'content_policy_violation',
        'not_found',
        'idempotency_key_reused',
        'daily_limit_exceeded',
    ];

    public function __construct(
        public readonly ?string $errorCode,
        string $message,
        public readonly int $retryAfter = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        return new self(
            $response->json('error.code'),
            $response->json('error.message') ?? "Pixelforge responded with HTTP {$response->status()}.",
            (int) $response->header('Retry-After'),
        );
    }

    /**
     * Overloads, rate and concurrency limits, server errors and lost connections can clear up.
     * Unknown errors count as retryable: Pixelforge may have created the generation, and the Idempotency-Key makes retrying safe.
     */
    public function isRetryable(): bool
    {
        return ! in_array($this->errorCode, self::PERMANENT, true);
    }
}

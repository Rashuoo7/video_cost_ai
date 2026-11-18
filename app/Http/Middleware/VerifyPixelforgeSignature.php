<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anyone can POST to the webhook URL, so only let through requests signed with our key's secret. The header is
 * "t=<unix timestamp>,v1=<hex HMAC-SHA256 of "<timestamp>.<raw body>">".
 */
class VerifyPixelforgeSignature
{
    /** Older signatures are rejected, so a captured request can't be replayed later. */
    private const int TOLERANCE_SECONDS = 300;

    public function __construct(
        #[Config('services.pixelforge.webhook_secret')] private readonly ?string $secret,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Fail closed: an empty secret would let anyone sign requests.
        abort_if(blank($this->secret), 500, 'PIXELFORGE_WEBHOOK_SECRET is not set.');

        $signature = [];

        foreach (explode(',', (string) $request->header('Pixelforge-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $signature[trim($key)] = $value;
        }

        $timestamp = (int) ($signature['t'] ?? 0);
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), (string) $this->secret);

        abort_unless(
            hash_equals($expected, $signature['v1'] ?? '') && abs(now()->getTimestamp() - $timestamp) <= self::TOLERANCE_SECONDS,
            400,
            'Invalid Pixelforge signature.',
        );

        return $next($request);
    }
}

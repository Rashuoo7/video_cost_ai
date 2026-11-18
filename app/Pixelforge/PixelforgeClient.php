<?php

namespace App\Pixelforge;

use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class PixelforgeClient
{
    public function __construct(
        #[Config('services.pixelforge.url')] private readonly string $url,
        #[Config('services.pixelforge.key')] private readonly ?string $key,
    ) {}

    /**
     * GET /v1/pricing. Fresh for 5 minutes; a stale copy is served for up to a day while it refreshes,
     * so a Pixelforge blip doesn't stop users seeing prices.
     *
     * @return array<string, mixed>
     */
    public function pricing(): array
    {
        return Cache::flexible('pixelforge.pricing', [300, 86400], fn () => $this->send('get', 'pricing'));
    }

    /**
     * POST /v1/generations. Retrying with the same key and body returns the original generation instead of a new one.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed> the generation object
     */
    public function createGeneration(array $body, string $idempotencyKey): array
    {
        return $this->send('post', 'generations', $body, ['Idempotency-Key' => $idempotencyKey]);
    }

    /**
     * @return array<string, mixed> the generation object
     */
    public function generation(string $id): array
    {
        return $this->send('get', 'generations/'.urlencode($id));
    }

    /**
     * GET /v1/usage: what Pixelforge billed this API key per UTC day, between two dates (inclusive, at most 92 days).
     *
     * @return array<string, mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send('get', 'usage', ['start_date' => $startDate, 'end_date' => $endDate]);
    }

    /**
     * @param  'get'|'post'  $method
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $body = [], array $headers = []): array
    {
        try {
            $response = Http::baseUrl($this->url)
                ->withToken((string) $this->key)
                ->withHeaders($headers)
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->{$method}($path, $body);
        } catch (ConnectionException $e) {
            throw new PixelforgeException(null, 'Could not reach Pixelforge.', previous: $e);
        }

        if ($response->failed()) {
            throw PixelforgeException::fromResponse($response);
        }

        return $response->json();
    }
}

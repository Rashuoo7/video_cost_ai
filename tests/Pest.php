<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * A user who bought some credits.
 */
function userWithCredits(int $dollars = 5): User
{
    $user = User::factory()->create();
    $user->creditPurchases()->create(['amount' => $dollars * 1_000_000_000]);

    return $user;
}

/*
|--------------------------------------------------------------------------
| Pixelforge fixtures
|--------------------------------------------------------------------------
|
| Shaped like the examples in Pixelforge's API reference, so the expected costs can be checked by hand.
|
*/

/**
 * The GET /v1/pricing response.
 *
 * @return array<string, mixed>
 */
function pixelforgePricing(): array
{
    return [
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];
}

/**
 * A Pixelforge generation object. A succeeded one has the docs' example usage, which costs Pixelforge
 * 12×$5 + 765×$10 + 6,450×$40 + 3,184×$12.50 per 1M tokens = $0.30551, and the user $0.3818875.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeGeneration(string $status = 'queued', array $overrides = []): array
{
    $succeeded = $status === 'succeeded';
    $failed = $status === 'failed';

    return array_replace([
        'id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K',
        'object' => 'generation',
        'status' => $status,
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png'],
        'metadata' => [],
        'image_url' => $succeeded ? 'https://pixelforge.promptedit.dev/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png?signature=abc' : null,
        'image_url_expires_at' => $succeeded ? '2026-11-05T14:03:29Z' : null,
        'usage' => $succeeded ? [
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ] : null,
        'rates' => pixelforgePricing()['rates'],
        'error' => $failed ? ['code' => 'generation_failed', 'message' => 'The model couldn’t produce an image for this prompt.'] : null,
        'created_at' => '2026-11-04T14:03:11Z',
        'started_at' => $status === 'queued' ? null : '2026-11-04T14:03:13Z',
        'completed_at' => $succeeded || $failed ? '2026-11-04T14:03:29Z' : null,
    ], $overrides);
}

/**
 * POST a webhook event to our endpoint, signed the way Pixelforge signs it.
 *
 * @param  array<string, mixed>  $event
 */
function sendWebhook(array $event, ?int $timestamp = null, string $secret = 'whsec_test'): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp ??= now()->getTimestamp();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    return test()->call('POST', route('webhooks.pixelforge'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], content: $body);
}

/**
 * A webhook event wrapping a generation object.
 *
 * @param  array<string, mixed>  $generation
 * @return array<string, mixed>
 */
function pixelforgeEvent(array $generation, string $id = 'evt_01JC2Z9B7K2M4P6R8T0V3X5Z7A'): array
{
    return [
        'id' => $id,
        'object' => 'event',
        'type' => 'generation.'.$generation['status'],
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => ['object' => $generation],
    ];
}

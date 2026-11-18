<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Run the job once, as if it were on its given attempt.
 */
function submitGeneration(Generation $generation, int $attempt = 1): SubmitGeneration
{
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;
    $job->handle(app(PixelforgeClient::class));

    return $job;
}

function pixelforgeError(string $code, int $status, int $retryAfter = 0): PromiseInterface
{
    return Http::response(
        ['error' => ['type' => 'error', 'code' => $code, 'message' => "Pixelforge says {$code}."]],
        $status,
        $retryAfter ? ['Retry-After' => (string) $retryAfter] : [],
    );
}

it('submits once per generation, with our id as the Idempotency-Key and in the metadata', function () {
    $generation = Generation::factory()->create(['reference_images' => ['https://example.com/a.png']]);
    Http::fake(['*/generations' => Http::response(pixelforgeGeneration('queued'), 202)]);

    submitGeneration($generation)->assertNotReleased();

    Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $generation->id)
        && $request->data() === [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => ['https://example.com/a.png'],
            'metadata' => ['generation_id' => $generation->id],
        ]);

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Queued)
        ->pixelforge_id->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->rates->toBe(pixelforgePricing()['rates']);
});

it('retries an overload after Retry-After, backing off further each time', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/generations' => pixelforgeError('overloaded', 503, retryAfter: 2)]);

    submitGeneration($generation, attempt: 1)->assertReleased(delay: 2);
    submitGeneration($generation, attempt: 3)->assertReleased(delay: 8);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending);
});

it('gives up after a few errors, and charges nothing', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/generations' => pixelforgeError('overloaded', 503, retryAfter: 2)]);

    foreach ([1, 2, 3] as $attempt) {
        submitGeneration($generation, $attempt)->assertReleased();
    }
    submitGeneration($generation, attempt: 4)->assertNotReleased();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->cost->toBe(0)
        ->error_code->toBe('overloaded');
});

it('waits for a free slot without using up the error budget', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/generations' => Http::sequence()
        ->pushResponse(pixelforgeError('concurrency_limit_exceeded', 429, retryAfter: 5))
        ->pushResponse(pixelforgeError('overloaded', 503, retryAfter: 2)),
    ]);

    submitGeneration($generation, attempt: 6)->assertReleased(delay: 60);
    // After waiting a while for a slot, a one-off overload is still retried.
    submitGeneration($generation, attempt: 7)->assertReleased(delay: 60);
});

it('stops waiting for a free slot eventually', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/generations' => pixelforgeError('concurrency_limit_exceeded', 429, retryAfter: 5)]);

    submitGeneration($generation, attempt: 10)->assertNotReleased();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Failed);
});

it('retries when it can’t tell whether Pixelforge received the request', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/generations' => Http::failedConnection()]);

    submitGeneration($generation)->assertReleased(delay: 2);
});

it('does not retry a prompt Pixelforge rejected', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*/generations' => pixelforgeError('content_policy_violation', 400)]);

    submitGeneration($generation)->assertNotReleased();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->cost->toBe(0)
        ->error_message->toBe('Pixelforge says content_policy_violation.');
});

it('keeps the newer status when a webhook beats the POST response', function () {
    $generation = Generation::factory()->create();

    Http::fake(['*/generations' => function () use ($generation) {
        // The generation starts processing, and its webhook lands, before Pixelforge answers our POST.
        $generation->syncFromPixelforge(pixelforgeGeneration('processing'));

        return Http::response(pixelforgeGeneration('queued'), 202);
    }]);

    submitGeneration($generation);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);
});

it('does nothing if Pixelforge already told us about the generation', function () {
    $generation = Generation::factory()->create();
    $generation->syncFromPixelforge(pixelforgeGeneration('processing'));
    Http::fake();

    submitGeneration($generation);

    Http::assertNothingSent();
});

it('marks a generation that could not be submitted as failed and free', function () {
    $generation = Generation::factory()->create();

    (new SubmitGeneration($generation))->failed(new RuntimeException('Worker died.'));

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->cost->toBe(0);
});

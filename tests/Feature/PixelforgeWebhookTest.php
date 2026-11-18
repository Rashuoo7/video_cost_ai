<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);
});

/**
 * A generation Pixelforge has accepted (and told us its id).
 */
function acceptedGeneration(): Generation
{
    $generation = Generation::factory()->create();
    $generation->syncFromPixelforge(pixelforgeGeneration('queued'));

    return $generation;
}

it('rejects requests that Pixelforge did not sign', function () {
    $generation = acceptedGeneration();

    sendWebhook(pixelforgeEvent(pixelforgeGeneration('succeeded')), secret: 'whsec_forged')->assertBadRequest();
    $this->postJson(route('webhooks.pixelforge'), pixelforgeEvent(pixelforgeGeneration('succeeded')))->assertBadRequest();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

it('rejects old signatures, so captured requests can’t be replayed', function () {
    acceptedGeneration();

    sendWebhook(pixelforgeEvent(pixelforgeGeneration('succeeded')), timestamp: now()->subMinutes(6)->getTimestamp())
        ->assertBadRequest();
});

it('refuses every request while no signing secret is configured', function () {
    config(['services.pixelforge.webhook_secret' => null]);

    sendWebhook(pixelforgeEvent(pixelforgeGeneration('succeeded')), secret: '')->assertServerError();
});

it('records the cost when a generation succeeds', function () {
    $generation = acceptedGeneration();

    sendWebhook(pixelforgeEvent(pixelforgeGeneration('succeeded')))->assertNoContent();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->provider_cost->toBe(305_510_000)
        ->cost->toBe(381_887_500)
        ->image_url->not->toBeNull()
        ->completed_at->toIso8601String()->toBe('2026-11-04T14:03:29+00:00');
});

it('records the cost once, however many times the event arrives', function () {
    $generation = acceptedGeneration();
    $event = pixelforgeEvent(pixelforgeGeneration('succeeded'));

    sendWebhook($event)->assertNoContent();
    sendWebhook($event)->assertNoContent();
    // The same outcome reported by a different event (say, a resend) changes nothing either.
    sendWebhook(pixelforgeEvent(pixelforgeGeneration('succeeded', ['usage' => [
        'input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 1, 'reasoning_tokens' => 1, 'total_tokens' => 3,
    ]]), id: 'evt_other'))->assertNoContent();

    expect($generation->refresh()->cost)->toBe(381_887_500)
        ->and(DB::table('pixelforge_events')->count())->toBe(2);
});

it('never moves a generation backwards when events arrive out of order', function () {
    $generation = acceptedGeneration();

    sendWebhook(pixelforgeEvent(pixelforgeGeneration('succeeded'), id: 'evt_succeeded'))->assertNoContent();
    sendWebhook(pixelforgeEvent(pixelforgeGeneration('processing'), id: 'evt_processing'))->assertNoContent();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->cost->toBe(381_887_500);
});

it('charges nothing for a failed generation', function () {
    $generation = acceptedGeneration();

    sendWebhook(pixelforgeEvent(pixelforgeGeneration('failed')))->assertNoContent();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Failed)
        ->cost->toBe(0)
        ->provider_cost->toBe(0)
        ->error_code->toBe('generation_failed');
});

it('finds the generation by its metadata when the webhook beats our POST response', function () {
    $generation = Generation::factory()->create(); // Pixelforge's id isn't known yet

    sendWebhook(pixelforgeEvent(pixelforgeGeneration('processing', [
        'metadata' => ['generation_id' => $generation->id],
    ])))->assertNoContent();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Processing)
        ->pixelforge_id->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');
});

it('lets Pixelforge overrule a submission we gave up on', function () {
    // We timed out and gave up, but Pixelforge had created the generation after all, and billed it.
    $generation = Generation::factory()->create();
    $generation->failSubmission(null, 'Could not reach Pixelforge.');
    $metadata = ['metadata' => ['generation_id' => $generation->id]];

    sendWebhook(pixelforgeEvent(pixelforgeGeneration('processing', $metadata), id: 'evt_processing'))->assertNoContent();

    // Our "failed", its error and its $0 are gone: the generation is in progress and not yet charged.
    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Processing)
        ->cost->toBeNull()
        ->provider_cost->toBeNull()
        ->error_code->toBeNull()
        ->error_message->toBeNull();

    sendWebhook(pixelforgeEvent(pixelforgeGeneration('succeeded', $metadata), id: 'evt_succeeded'))->assertNoContent();

    expect($generation->refresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->cost->toBe(381_887_500)
        ->error_message->toBeNull();
});

it('acknowledges test pings and events for generations it doesn’t know', function () {
    sendWebhook(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'created_at' => '2026-11-04T14:03:29Z', 'data' => []])
        ->assertNoContent();
    sendWebhook(pixelforgeEvent(pixelforgeGeneration('succeeded', ['id' => 'gen_unknown'])))
        ->assertNoContent();
});

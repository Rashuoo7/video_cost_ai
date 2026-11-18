<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;

it('polls Pixelforge for generations whose webhooks never arrived', function () {
    $stuck = Generation::factory()->create();
    $stuck->syncFromPixelforge(pixelforgeGeneration('processing'));
    $this->travel(5)->minutes();

    $recent = Generation::factory()->create();
    $recent->syncFromPixelforge(pixelforgeGeneration('processing', ['id' => 'gen_recent']));

    Http::fake(['*/generations/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K' => pixelforgeGeneration('succeeded')]);

    $this->artisan('generations:sync')->assertSuccessful();

    expect($stuck->refresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->cost->toBe(381_887_500)
        ->and($recent->refresh()->status)->toBe(GenerationStatus::Processing);

    Http::assertSentCount(1);
});

it('keeps going when one lookup fails', function () {
    $first = Generation::factory()->create();
    $first->syncFromPixelforge(pixelforgeGeneration('processing', ['id' => 'gen_first']));
    $second = Generation::factory()->create();
    $second->syncFromPixelforge(pixelforgeGeneration('processing', ['id' => 'gen_second']));
    $this->travel(5)->minutes();

    Http::fake([
        '*/generations/gen_first' => Http::response(['error' => ['code' => 'not_found', 'message' => 'No such generation.']], 404),
        '*/generations/gen_second' => pixelforgeGeneration('failed', ['id' => 'gen_second']),
    ]);

    $this->artisan('generations:sync')->assertSuccessful();

    expect($second->refresh()->status)->toBe(GenerationStatus::Failed);
});

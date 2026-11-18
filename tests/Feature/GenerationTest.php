<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Pricing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Http::fake(['*/pricing' => pixelforgePricing()]);
    Queue::fake();
});

it('shows users only their own generations', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->create();
    Generation::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Generations')
            ->has('generations.data', 1)
            ->where('sizes', Generation::SIZES)
            ->where('qualities', Generation::QUALITIES));
});

it('prices the live estimate from Pixelforge’s published pricing', function () {
    $this->actingAs(User::factory()->create())
        ->getJson(route('generations.estimate', [
            'characters' => 400,
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => 2,
        ]))
        ->assertOk()
        ->assertExactJson((array) Pricing::estimate(pixelforgePricing(), 400, '1536x1024', 'high', 2));
});

it('records a generation with its estimate and submits it in the background', function () {
    $user = userWithCredits();
    $prompt = str_repeat('🎨', 40); // 40 characters, but 160 bytes

    $this->actingAs($user)
        ->post(route('generations.store'), [
            'prompt' => $prompt,
            'size' => '1024x1536',
            'quality' => 'low',
            'reference_images' => ['https://example.com/a.png'],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    $generation = $user->generations()->sole();
    $estimate = Pricing::estimate(pixelforgePricing(), 40, '1024x1536', 'low', 1);

    expect($generation)
        ->status->toBe(GenerationStatus::Pending)
        ->estimated_cost->toBe($estimate->expected)
        ->max_cost->toBe($estimate->max)
        ->cost->toBeNull();

    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($generation));
});

it('checks Pixelforge’s limits before submitting anything', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('generations.store'), [
            'prompt' => str_repeat('a', 4001),
            'size' => '2048x2048',
            'quality' => 'ultra',
            'reference_images' => ['ftp://example.com/a.png', 'b', 'c', 'd', 'e'],
        ])
        ->assertSessionHasErrors(['prompt', 'size', 'quality', 'reference_images', 'reference_images.0']);

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('only accepts reference images as a plain list, as Pixelforge expects', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('generations.store'), [
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => ['style' => 'https://example.com/a.png'],
        ])
        ->assertSessionHasErrors('reference_images');
});

it('limits how fast one user can generate, since every user shares Pixelforge’s limits', function () {
    $user = userWithCredits();
    $generate = fn () => $this->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'A lighthouse',
        'size' => '1024x1024',
        'quality' => 'low',
    ]);

    foreach (range(1, 10) as $request) {
        $generate()->assertSessionHasNoErrors();
    }

    $generate()->assertSessionHasErrors(['prompt' => 'That’s a lot of images at once. Please wait a minute and try again.']);
    expect($user->generations()->count())->toBe(10);
});

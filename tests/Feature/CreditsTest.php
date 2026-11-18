<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Pricing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Http::fake(['*/pricing' => pixelforgePricing()]);
    Queue::fake();
});

/**
 * POST the generate form for a low quality square image: at most $0.0219… at the docs' prices.
 */
function generateCheapImage(User $user): TestResponse
{
    return test()->actingAs($user)->post(route('generations.store'), [
        'prompt' => 'A lighthouse',
        'size' => '1024x1024',
        'quality' => 'low',
    ]);
}

function cheapImageMaxCost(): int
{
    return Pricing::estimate(pixelforgePricing(), 12, '1024x1024', 'low', 0)->max;
}

it('adds the credits a user buys', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('credits.store'), ['amount' => 10])->assertSessionHasNoErrors();

    expect($user->creditBalance())->toBe(['balance' => 10_000_000_000, 'held' => 0, 'available' => 10_000_000_000]);
});

it('only sells the listed amounts', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('credits.store'), ['amount' => 1_000_000])->assertSessionHasErrors('amount');

    expect($user->creditPurchases()->count())->toBe(0);
});

it('limits how often a user can buy credits', function () {
    $user = User::factory()->create();

    foreach (range(1, 10) as $purchase) {
        $this->actingAs($user)->post(route('credits.store'), ['amount' => 5])->assertSessionHasNoErrors();
    }

    $this->actingAs($user)->post(route('credits.store'), ['amount' => 5])->assertTooManyRequests();
    expect($user->creditPurchases()->count())->toBe(10);
});

it('works out credits from purchases, charges and the holds of unfinished generations', function () {
    $user = userWithCredits(5);
    Generation::factory()->for($user)->succeeded(providerCost: 100_000_000)->create(); // charged $0.125
    Generation::factory()->for($user)->create(['status' => GenerationStatus::Failed, 'cost' => 0, 'provider_cost' => 0]);
    Generation::factory()->for($user)->create(['status' => GenerationStatus::Processing, 'max_cost' => 400_000_000]);
    Generation::factory()->for($user)->create(['max_cost' => 30_000_000]); // pending

    expect($user->creditBalance())->toBe([
        'balance' => 4_875_000_000,
        'held' => 430_000_000,
        'available' => 4_445_000_000,
    ]);
});

it('blocks a generation the user can’t afford', function () {
    $user = User::factory()->create();
    $user->creditPurchases()->create(['amount' => cheapImageMaxCost() - 1]);

    generateCheapImage($user)->assertSessionHasErrors('credits');

    expect($user->generations()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('allows a generation when exactly its maximum cost is available', function () {
    $user = User::factory()->create();
    $user->creditPurchases()->create(['amount' => cheapImageMaxCost()]);

    generateCheapImage($user)->assertSessionHasNoErrors();

    expect($user->creditBalance())->toBe(['balance' => cheapImageMaxCost(), 'held' => cheapImageMaxCost(), 'available' => 0]);
    Queue::assertPushed(SubmitGeneration::class);
});

it('holds credits while a generation runs, so they can’t be spent twice', function () {
    $user = User::factory()->create();
    $user->creditPurchases()->create(['amount' => cheapImageMaxCost() * 2 - 1]);

    generateCheapImage($user)->assertSessionHasNoErrors();
    generateCheapImage($user)->assertSessionHasErrors('credits');

    expect($user->generations()->count())->toBe(1);
});

it('charges the actual cost and releases the hold when a generation succeeds', function () {
    $user = userWithCredits(5);
    $generation = Generation::factory()->for($user)->create(['max_cost' => 500_000_000]);

    $generation->syncFromPixelforge(pixelforgeGeneration('succeeded'));

    expect($user->creditBalance())->toBe([
        'balance' => 5_000_000_000 - 381_887_500,
        'held' => 0,
        'available' => 5_000_000_000 - 381_887_500,
    ]);
});

it('releases the hold without charging when a generation fails', function () {
    $user = userWithCredits(5);
    $generation = Generation::factory()->for($user)->create(['max_cost' => 500_000_000]);

    $generation->syncFromPixelforge(pixelforgeGeneration('failed'));

    expect($user->creditBalance())->toBe(['balance' => 5_000_000_000, 'held' => 0, 'available' => 5_000_000_000]);
});

it('shares the credits with every page, for the sidebar', function () {
    $user = userWithCredits(5);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('credits.available', 5_000_000_000)
            ->where('creditAmounts', [5, 10, 20]));
});

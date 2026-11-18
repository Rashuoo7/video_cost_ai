<?php

use App\Pixelforge\Pricing;
use Brick\Math\Exception\RoundingNecessaryException;

it('costs a generation exactly, matching the usage example in the docs', function () {
    $usage = ['input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950];

    // The docs report "0.169080000" for this usage.
    expect(Pricing::providerCost($usage, pixelforgePricing()['rates']))->toBe(169_080_000);
});

it('ignores token counts that have no rate, such as total_tokens', function () {
    $usage = ['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0, 'total_tokens' => 1];

    expect(Pricing::providerCost($usage, pixelforgePricing()['rates']))->toBe(5_000); // $5 per 1M tokens
});

it('refuses to round a cost rather than record it inexactly', function () {
    Pricing::providerCost(['input_text_tokens' => 1], ['input_text_tokens' => '0.0001']);
})->throws(RoundingNecessaryException::class);

it('charges users the provider cost plus 25%', function () {
    expect(Pricing::charge(305_510_000))->toBe(381_887_500)
        ->and(Pricing::charge(0))->toBe(0);
});

it('rounds a fraction of a nanodollar up', function () {
    expect(Pricing::charge(1))->toBe(2); // 1.25 nanodollars
});

it('estimates the worst case exactly as the docs work it out', function () {
    // 400 characters, 2 reference images, high quality 1536x1024: at most $0.373875 from Pixelforge.
    $estimate = Pricing::estimate(pixelforgePricing(), 400, '1536x1024', 'high', 2);

    expect($estimate->max)->toBe(Pricing::charge(373_875_000))
        ->and($estimate->min)->toBe(Pricing::charge(
            85 * 5_000 + 1_530 * 10_000 + 6_450 * 40_000 + 1_000 * 12_500, // 15% fewer text tokens, least reasoning
        ))
        ->and($estimate->expected)->toBe(Pricing::charge(
            100 * 5_000 + 1_530 * 10_000 + 6_450 * 40_000 + 4_500 * 12_500, // estimated text tokens, mid-range reasoning
        ));
});

it('rounds the text token range outwards', function () {
    // 10 characters → ceil(10 / 4) = 3 tokens, so between floor(2.55) = 2 and ceil(3.45) = 4.
    $estimate = Pricing::estimate(pixelforgePricing(), 10, '1024x1024', 'low', 0);

    expect($estimate->min)->toBe(Pricing::charge(2 * 5_000 + 280 * 40_000))
        ->and($estimate->max)->toBe(Pricing::charge(4 * 5_000 + 280 * 40_000 + 500 * 12_500));
});

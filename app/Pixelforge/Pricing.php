<?php

namespace App\Pixelforge;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Cost maths. All money is integer nanodollars (1e-9 USD), so nothing is ever lost to floating point.
 */
final class Pricing
{
    /** Users pay Pixelforge's cost plus 25%. */
    public const string MARKUP = '1.25';

    /**
     * What Pixelforge charges for a generation: tokens × rate, where rates are dollars per 1M tokens.
     *
     * @param  array<string, int>  $usage  token counts, keyed like the rates
     * @param  array<string, string>  $rates  dollars per 1M tokens, e.g. "12.50"
     */
    public static function providerCost(array $usage, array $rates): int
    {
        $nanodollars = BigDecimal::zero();

        foreach ($rates as $tokenType => $ratePerMillion) {
            // $ per 1M tokens × 1e9 nanodollars per $ ÷ 1e6 = × 1000 nanodollars per token.
            $nanodollars = $nanodollars->plus(BigDecimal::of($ratePerMillion)->multipliedBy($usage[$tokenType])->multipliedBy(1000));
        }

        // toInt() throws instead of rounding if a rate ever gets precise enough to need it.
        return $nanodollars->toInt();
    }

    /**
     * A Pixelforge money string such as "0.169080000" (exact, nine decimals) in nanodollars.
     */
    public static function nanodollars(string $dollars): int
    {
        return BigDecimal::of($dollars)->multipliedBy(1_000_000_000)->toInt();
    }

    /**
     * What we charge the user for a provider cost. Exact at today's rates; would round up to the next nanodollar otherwise.
     */
    public static function charge(int $providerCost): int
    {
        return BigDecimal::of($providerCost)->multipliedBy(self::MARKUP)->toScale(0, RoundingMode::Up)->toInt();
    }

    /**
     * What a generation will cost the user, from GET /v1/pricing. Output and reference-image tokens are exact. Text
     * tokens land within ±variance of ceil(characters / characters-per-token), and reasoning tokens within a range set by
     * quality; both are only known once the generation completes.
     *
     * @param  array<string, mixed>  $pricing  the GET /v1/pricing response
     */
    public static function estimate(array $pricing, int $characters, string $size, string $quality, int $referenceImages): Estimate
    {
        $tokens = $pricing['tokens'];
        $textTokens = BigDecimal::of((int) ceil($characters / $tokens['characters_per_text_token']));
        $variance = BigDecimal::of((string) $tokens['text_token_variance']);
        $reasoningTokens = $tokens['reasoning'][$quality];

        $charge = fn (BigDecimal $text, int $reasoning): int => self::charge(self::providerCost([
            'input_text_tokens' => $text->toInt(),
            'input_image_tokens' => $referenceImages * $tokens['per_reference_image'],
            'output_tokens' => $tokens['output'][$quality][$size],
            'reasoning_tokens' => $reasoning,
        ], $pricing['rates']));

        return new Estimate(
            min: $charge($textTokens->multipliedBy(BigDecimal::one()->minus($variance))->toScale(0, RoundingMode::Floor), $reasoningTokens['min']),
            expected: $charge($textTokens, intdiv($reasoningTokens['min'] + $reasoningTokens['max'], 2)),
            max: $charge($textTokens->multipliedBy(BigDecimal::one()->plus($variance))->toScale(0, RoundingMode::Ceiling), $reasoningTokens['max']),
        );
    }
}

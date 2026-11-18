<?php

namespace App\Pixelforge;

/**
 * What a generation will cost the user, in nanodollars.
 */
final readonly class Estimate
{
    public function __construct(
        public int $min,
        public int $expected,
        public int $max,
    ) {}
}

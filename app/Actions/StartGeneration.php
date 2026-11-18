<?php

namespace App\Actions;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\Pricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StartGeneration
{
    public function __construct(private readonly PixelforgeClient $pixelforge) {}

    /**
     * Price the generation, hold the most it could cost against the user's credits, record it, and submit it to
     * Pixelforge in the background.
     *
     * @param  list<string>  $referenceImages
     *
     * @throws ValidationException when the user can't afford it
     */
    public function __invoke(User $user, string $prompt, string $size, string $quality, array $referenceImages = []): Generation
    {
        $estimate = Pricing::estimate($this->pixelforge->pricing(), mb_strlen($prompt), $size, $quality, count($referenceImages));

        $generation = DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $estimate) {
            // Lock the user so two submissions at once can't both spend the same credits.
            User::whereKey($user->id)->lockForUpdate()->first();

            if ($user->creditBalance()['available'] < $estimate->max) {
                throw ValidationException::withMessages([
                    'credits' => __('You don’t have enough credits for this image. Buy credits to continue.'),
                ]);
            }

            return $user->generations()->create([
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'estimated_cost' => $estimate->expected,
                'max_cost' => $estimate->max,
            ]);
        });

        // The row is committed before Pixelforge hears about it, so even a webhook that beats our POST response finds it.
        SubmitGeneration::dispatch($generation);

        return $generation;
    }
}

<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Pricing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'prompt' => fake()->sentence(),
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'estimated_cost' => 25_000_000,
            'max_cost' => 45_000_000,
        ];
    }

    /**
     * A generation Pixelforge finished and billed.
     */
    public function succeeded(int $providerCost = 305_510_000, ?string $completedAt = null): static
    {
        return $this->state(fn () => [
            'pixelforge_id' => 'gen_'.Str::ulid(),
            'status' => GenerationStatus::Succeeded,
            'provider_cost' => $providerCost,
            'cost' => Pricing::charge($providerCost),
            'completed_at' => $completedAt ?? now(),
        ]);
    }
}

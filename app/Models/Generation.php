<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Pixelforge\Pricing;
use Closure;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An image generation and what it cost. Money is in integer nanodollars (1e-9 USD).
 *
 * @property string $id also the Idempotency-Key and metadata.generation_id we send to Pixelforge
 * @property int|null $user_id
 * @property string|null $pixelforge_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_images
 * @property int $estimated_cost what we expected to charge the user
 * @property int $max_cost the most it could cost the user
 * @property int|null $provider_cost what Pixelforge charged us
 * @property int|null $cost what we charged the user
 * @property array<string, string>|null $rates dollars per 1M tokens, fixed by Pixelforge when the generation was created
 * @property array<string, int>|null $usage
 * @property int $submission_errors failed attempts to submit it, not counting waits for a free slot
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property Carbon|null $completed_at when Pixelforge finished it, which is the day it bills
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['prompt', 'size', 'quality', 'reference_images', 'estimated_cost', 'max_cost'])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    public const array SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    public const array QUALITIES = ['low', 'medium', 'high'];

    protected $attributes = [
        'status' => GenerationStatus::Pending->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'image_url_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The POST /v1/generations body. It must be identical on every retry, or Pixelforge rejects the Idempotency-Key.
     *
     * @return array<string, mixed>
     */
    public function pixelforgeRequest(): array
    {
        return [
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'metadata' => ['generation_id' => $this->id],
        ];
    }

    /**
     * Find the generation a Pixelforge generation object belongs to. The metadata matches it even when a webhook
     * beats our POST response, so we don't know the Pixelforge id yet.
     *
     * @param  array<string, mixed>  $remote
     */
    public static function findForPixelforge(array $remote): ?self
    {
        $id = $remote['metadata']['generation_id'] ?? null;

        return static::where('pixelforge_id', $remote['id'])
            ->when(Str::isUuid($id), fn (Builder $query) => $query->orWhere('id', $id))
            ->first();
    }

    /**
     * Record Pixelforge's view of this generation. Webhooks, polling and API responses arrive late, twice or out of
     * order, so the status only ever moves forward, and the cost is written in the same conditional update as the
     * final status: it can't be recorded twice.
     *
     * @param  array<string, mixed>  $remote  a Pixelforge generation object
     * @return bool whether anything changed
     */
    public function syncFromPixelforge(array $remote): bool
    {
        $status = GenerationStatus::from($remote['status']);

        $providerCost = match ($status) {
            GenerationStatus::Succeeded => Pricing::providerCost($remote['usage'], $remote['rates']),
            GenerationStatus::Failed => 0,
            default => null,
        };

        // Until Pixelforge has confirmed the generation, our own "failed" (we gave up submitting it) isn't final:
        // if it turns out Pixelforge created it after all, Pixelforge's record replaces ours, error and $0 included.
        return $this->updateWhere(fn (Builder $query) => $query
            ->whereIn('status', $status->predecessors())
            ->orWhereNull('pixelforge_id'), [
                'pixelforge_id' => $remote['id'],
                'status' => $status,
                'rates' => $remote['rates'],
                'usage' => $remote['usage'],
                'provider_cost' => $providerCost,
                'cost' => $providerCost === null ? null : Pricing::charge($providerCost),
                'error_code' => $remote['error']['code'] ?? null,
                'error_message' => $remote['error']['message'] ?? null,
                'image_url' => $remote['image_url'],
                'image_url_expires_at' => $remote['image_url_expires_at'],
                'completed_at' => $remote['completed_at'],
            ]);
    }

    /**
     * Mark a generation Pixelforge never accepted as failed. Nothing was billed, so it's free.
     */
    public function failSubmission(?string $errorCode, string $message): bool
    {
        return $this->updateWhere(fn (Builder $query) => $query->where('status', GenerationStatus::Pending), [
            'status' => GenerationStatus::Failed,
            'provider_cost' => 0,
            'cost' => 0,
            'error_code' => $errorCode ?? 'submission_failed',
            'error_message' => $message,
        ]);
    }

    /**
     * Compare-and-set: apply the attributes only if the row still matches the condition, atomically in the database.
     *
     * @param  Closure(Builder<self>): mixed  $condition
     * @param  array<string, mixed>  $attributes
     */
    private function updateWhere(Closure $condition, array $attributes): bool
    {
        $this->forceFill($attributes);

        // Write every given column, not just those that differ from a possibly stale in-memory copy.
        $updated = static::whereKey($this->getKey())
            ->where($condition)
            ->update(Arr::only($this->getAttributes(), array_keys($attributes))) === 1;

        $this->refresh();

        return $updated;
    }
}

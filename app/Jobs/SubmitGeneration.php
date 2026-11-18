<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Every release counts as an attempt, so this caps waiting for a free slot (about 5 minutes of backoff). */
    public int $tries = 10;

    /** Give up after this many errors other than "no free slot". */
    private const int MAX_ERRORS = 4;

    public function __construct(public Generation $generation) {}

    public function handle(PixelforgeClient $pixelforge): void
    {
        if ($this->generation->status !== GenerationStatus::Pending) {
            return; // Pixelforge already told us about it, so there's nothing to submit.
        }

        try {
            $remote = $pixelforge->createGeneration($this->generation->pixelforgeRequest(), $this->generation->id);
        } catch (PixelforgeException $e) {
            $delay = $this->retryDelay($e);

            if ($delay === null) {
                $this->generation->failSubmission($e->errorCode, $e->getMessage());
            } else {
                $this->release($delay);
            }

            return;
        }

        $this->generation->syncFromPixelforge($remote);
    }

    /**
     * Seconds to wait before retrying, or null to give up. Waits at least Retry-After and backs off further each time.
     * Waiting for a free slot is expected (one opens when a generation finishes), so it doesn't use up the error budget.
     */
    private function retryDelay(PixelforgeException $e): ?int
    {
        if ($e->errorCode !== 'concurrency_limit_exceeded') {
            $this->generation->increment('submission_errors');
        }

        if (! $e->isRetryable() || $this->generation->submission_errors >= self::MAX_ERRORS || $this->attempts() >= $this->tries) {
            return null;
        }

        return max($e->retryAfter, min(2 ** $this->attempts(), 60));
    }

    /**
     * The job crashed or ran out of attempts.
     */
    public function failed(?Throwable $exception): void
    {
        $this->generation->failSubmission(null, 'We couldn’t submit this generation. You weren’t charged.');
    }
}

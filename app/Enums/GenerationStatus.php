<?php

namespace App\Enums;

enum GenerationStatus: string
{
    /** Saved by us, not yet accepted by Pixelforge. */
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * @return list<self>
     */
    public static function unfinished(): array
    {
        return [self::Pending, self::Queued, self::Processing];
    }

    /**
     * The statuses a generation may move to this one from: anything earlier in the lifecycle.
     *
     * @return list<self>
     */
    public function predecessors(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => $status->step() < $this->step()));
    }

    private function step(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }
}

<?php

namespace App\Generations;

enum GenerationStatus: string
{
    case Pending = 'pending';         // local only: not yet accepted by Pixelforge
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** Status only ever moves to a higher rank. Both final states share the top rank. */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /** Users see the four statuses of the brief; "pending" is shown as Queued. */
    public function label(): string
    {
        return match ($this) {
            self::Pending, self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
        };
    }

    /** @return list<self> */
    public static function inFlight(): array
    {
        return [self::Pending, self::Queued, self::Processing];
    }
}

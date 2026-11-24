<?php

namespace App\Enums;

enum GenerationStatus: string
{
    /** Saved locally and waiting to be accepted by Pixelforge (we may be retrying a busy API). */
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Succeeded and failed are terminal: nothing may move a generation out of them.
     */
    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /**
     * Position in the lifecycle. Status only ever moves forward, which is what lets us
     * ignore stale webhooks and responses that arrive out of order.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return ! $this->isFinal() && $next->rank() > $this->rank();
    }

    /**
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Pending, self::Queued, self::Processing];
    }
}

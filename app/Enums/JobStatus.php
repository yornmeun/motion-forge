<?php

namespace App\Enums;

enum JobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case WaitingApproval = 'waiting_approval';

    public function isFinished(): bool
    {
        return in_array($this, [self::Succeeded, self::Failed, self::Cancelled], true);
    }

    public function tone(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Failed => 'error',
            self::Cancelled => 'muted',
            self::WaitingApproval => 'warning',
            default => 'accent',
        };
    }
}

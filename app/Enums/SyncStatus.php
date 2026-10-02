<?php

namespace App\Enums;

enum SyncStatus: string
{
    case Idle = 'idle';
    case Queued = 'queued';
    case Syncing = 'syncing';
    case Waiting = 'waiting';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Syncing, self::Waiting], true);
    }
}

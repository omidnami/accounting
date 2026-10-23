<?php

namespace App\Enums;

enum JournalEntryKind: string
{
    case System = 'system';
    case Manual = 'manual';
    case Opening = 'opening';
    case Closing = 'closing';
    case Reversal = 'reversal';

    public function isManual(): bool
    {
        return in_array($this, [self::Manual, self::Opening], true);
    }
}

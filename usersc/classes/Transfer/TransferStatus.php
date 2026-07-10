<?php

declare(strict_types=1);

namespace ElanRegistry\Transfer;

enum TransferStatus: string
{
    case Pending   = 'pending';
    case Approved  = 'approved'; // Included to match DB enum; non-terminal so DB rows with this value are safe to read without ValueError
    case Completed = 'completed';
    case Denied    = 'denied';
    case Expired   = 'expired';

    public function isTerminal(): bool
    {
        return match($this) {
            self::Pending, self::Approved             => false,
            self::Completed, self::Denied, self::Expired => true,
        };
    }
}

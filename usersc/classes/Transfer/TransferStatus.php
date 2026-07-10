<?php

declare(strict_types=1);

namespace ElanRegistry\Transfer;

enum TransferStatus: string
{
    case Pending   = 'pending';
    case Approved  = 'approved'; // Reserved — DB enum includes this; no code transitions to it, but isTerminal() classifies it as non-terminal so future transitions are safe to add
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

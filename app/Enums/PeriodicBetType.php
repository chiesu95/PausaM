<?php

namespace App\Enums;

enum PeriodicBetType: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'giornaliera',
            self::Weekly => 'settimanale',
        };
    }
}

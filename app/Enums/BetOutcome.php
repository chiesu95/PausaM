<?php

namespace App\Enums;

enum BetOutcome: string
{
    case Under15 = 'under_15';
    case From15To30 = 'from_15_to_30';
    case From30To45 = 'from_30_to_45';
    case Over45 = 'over_45';

    /**
     * @return array<string, self>
     */
    public static function aliases(): array
    {
        return [
            'under15' => self::Under15,
            'under 15' => self::Under15,
            'under_15' => self::Under15,
            '<15' => self::Under15,
            '15_30' => self::From15To30,
            '15-30' => self::From15To30,
            'from15to30' => self::From15To30,
            'from 15 to 30' => self::From15To30,
            'from_15_to_30' => self::From15To30,
            '30_45' => self::From30To45,
            '30-45' => self::From30To45,
            'from30to45' => self::From30To45,
            'from 30 to 45' => self::From30To45,
            'from_30_to_45' => self::From30To45,
            'over45' => self::Over45,
            'over 45' => self::Over45,
            'over_45' => self::Over45,
            '>45' => self::Over45,
        ];
    }

    public static function fromInput(string $value): ?self
    {
        $normalizedValue = strtolower(trim($value));

        return self::aliases()[$normalizedValue] ?? null;
    }

    public static function fromDurationInMinutes(float $minutes): self
    {
        if ($minutes < 15) {
            return self::Under15;
        }

        if ($minutes < 30) {
            return self::From15To30;
        }

        if ($minutes < 45) {
            return self::From30To45;
        }

        return self::Over45;
    }

    public function label(): string
    {
        return match ($this) {
            self::Under15 => 'under 15 minuti',
            self::From15To30 => 'from 15 to 30 minuti',
            self::From30To45 => 'from 30 to 45 minuti',
            self::Over45 => 'over 45 minuti',
        };
    }
}

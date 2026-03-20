<?php

namespace App\Enums;

enum DailyBetChoice: string
{
    case Under30 = 'under_30';
    case Under1Hour = 'under_1h';
    case Under1Hour30 = 'under_1h30';
    case Over1Hour30 = 'over_1h30';

    /**
     * @return array<string, self>
     */
    public static function aliases(): array
    {
        return [
            'under30' => self::Under30,
            'under 30' => self::Under30,
            'under_30' => self::Under30,
            'under30m' => self::Under30,
            'under 30m' => self::Under30,
            'under1h' => self::Under1Hour,
            'under 1h' => self::Under1Hour,
            'under_1h' => self::Under1Hour,
            'under1ora' => self::Under1Hour,
            'under 1 ora' => self::Under1Hour,
            'under1h30' => self::Under1Hour30,
            'under 1h30' => self::Under1Hour30,
            'under_1h30' => self::Under1Hour30,
            'under 1h e 30' => self::Under1Hour30,
            'under 1 ora e 30' => self::Under1Hour30,
            'under 1h e 30m' => self::Under1Hour30,
            'over1h30' => self::Over1Hour30,
            'over 1h30' => self::Over1Hour30,
            'over_1h30' => self::Over1Hour30,
            'over 1h e 30' => self::Over1Hour30,
            'over 1 ora e 30' => self::Over1Hour30,
            'over 90' => self::Over1Hour30,
            'over90' => self::Over1Hour30,
        ];
    }

    public static function fromInput(string $value): ?self
    {
        $normalizedValue = self::normalize($value);

        return self::aliases()[$normalizedValue] ?? null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Under30 => 'under 30 minuti',
            self::Under1Hour => 'under 1 ora',
            self::Under1Hour30 => 'under 1 ora e 30',
            self::Over1Hour30 => 'over 1 ora e 30',
        };
    }

    public function isWinning(float $totalMinutes): bool
    {
        return match ($this) {
            self::Under30 => $totalMinutes <= 30,
            self::Under1Hour => $totalMinutes > 30 && $totalMinutes <= 60,
            self::Under1Hour30 => $totalMinutes > 60 && $totalMinutes <= 90,
            self::Over1Hour30 => $totalMinutes > 90,
        };
    }

    protected static function normalize(string $value): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($value))) ?? '';
    }
}

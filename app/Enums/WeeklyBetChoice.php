<?php

namespace App\Enums;

enum WeeklyBetChoice: string
{
    case Under3Hours = 'under_3h';
    case Under4Hours = 'under_4h';
    case Under6Hours = 'under_6h';
    case Over6Hours = 'over_6h';

    /**
     * @return array<string, self>
     */
    public static function aliases(): array
    {
        return [
            'under3h' => self::Under3Hours,
            'under 3h' => self::Under3Hours,
            'under_3h' => self::Under3Hours,
            'under 3 ore' => self::Under3Hours,
            'under4h' => self::Under4Hours,
            'under 4h' => self::Under4Hours,
            'under_4h' => self::Under4Hours,
            'under 4 ore' => self::Under4Hours,
            'under6h' => self::Under6Hours,
            'under 6h' => self::Under6Hours,
            'under_6h' => self::Under6Hours,
            'under 6 ore' => self::Under6Hours,
            'over6h' => self::Over6Hours,
            'over 6h' => self::Over6Hours,
            'over_6h' => self::Over6Hours,
            'over 6 ore' => self::Over6Hours,
            'over360' => self::Over6Hours,
            'over 360' => self::Over6Hours,
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
            self::Under3Hours => 'under 3 ore',
            self::Under4Hours => 'under 4 ore',
            self::Under6Hours => 'under 6 ore',
            self::Over6Hours => 'over 6 ore',
        };
    }

    public function isWinning(float $totalMinutes): bool
    {
        return match ($this) {
            self::Under3Hours => $totalMinutes <= 180,
            self::Under4Hours => $totalMinutes > 180 && $totalMinutes <= 240,
            self::Under6Hours => $totalMinutes > 240 && $totalMinutes <= 360,
            self::Over6Hours => $totalMinutes > 360,
        };
    }

    protected static function normalize(string $value): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($value))) ?? '';
    }
}

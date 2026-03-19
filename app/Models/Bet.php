<?php

namespace App\Models;

use App\Enums\BetOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bet extends Model
{
    /** @use HasFactory<\Database\Factories\BetFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'bet_round_id',
        'telegram_player_id',
        'choice',
        'is_winner',
        'awarded_points',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'choice' => BetOutcome::class,
            'is_winner' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<BetRound, $this>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(BetRound::class, 'bet_round_id');
    }

    /**
     * @return BelongsTo<TelegramPlayer, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(TelegramPlayer::class, 'telegram_player_id');
    }
}

<?php

namespace App\Models;

use App\Enums\PeriodicBetType;
use Database\Factories\PeriodicBetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodicBet extends Model
{
    /** @use HasFactory<PeriodicBetFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'telegram_chat_id',
        'telegram_player_id',
        'type',
        'period_start_date',
        'choice',
        'resolved_at',
        'resolved_total_minutes',
        'is_winner',
        'awarded_points',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PeriodicBetType::class,
            'period_start_date' => 'date',
            'resolved_at' => 'datetime',
            'resolved_total_minutes' => 'float',
            'is_winner' => 'boolean',
            'awarded_points' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TelegramPlayer, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(TelegramPlayer::class, 'telegram_player_id');
    }
}

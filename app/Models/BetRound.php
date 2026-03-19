<?php

namespace App\Models;

use App\Enums\BetOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BetRound extends Model
{
    /** @use HasFactory<\Database\Factories\BetRoundFactory> */
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'telegram_chat_id',
        'bathroom_session_id',
        'status',
        'result',
        'resolved_at',
        'opened_by_telegram_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => BetOutcome::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * @return BelongsTo<BathroomSession, $this>
     */
    public function bathroomSession(): BelongsTo
    {
        return $this->belongsTo(BathroomSession::class);
    }

    /**
     * @return HasMany<Bet, $this>
     */
    public function bets(): HasMany
    {
        return $this->hasMany(Bet::class);
    }
}

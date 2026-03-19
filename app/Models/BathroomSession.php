<?php

namespace App\Models;

use App\Enums\BetOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BathroomSession extends Model
{
    /** @use HasFactory<\Database\Factories\BathroomSessionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'person_name',
        'started_at',
        'ended_at',
        'duration_minutes',
        'outcome',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_minutes' => 'float',
            'outcome' => BetOutcome::class,
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * @return HasOne<BetRound, $this>
     */
    public function betRound(): HasOne
    {
        return $this->hasOne(BetRound::class);
    }
}

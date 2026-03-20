<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelegramChatState extends Model
{
    /** @use HasFactory<\Database\Factories\TelegramChatStateFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'telegram_chat_id',
        'pinned_round_message_id',
        'pinned_daily_message_id',
        'pinned_daily_for_date',
        'pinned_weekly_message_id',
        'pinned_weekly_for_week_start_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pinned_round_message_id' => 'integer',
            'pinned_daily_message_id' => 'integer',
            'pinned_daily_for_date' => 'date',
            'pinned_weekly_message_id' => 'integer',
            'pinned_weekly_for_week_start_date' => 'date',
        ];
    }
}

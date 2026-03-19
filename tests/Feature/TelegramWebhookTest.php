<?php

use App\Enums\BetOutcome;
use App\Models\BathroomSession;
use App\Models\Bet;
use App\Models\BetRound;
use App\Models\TelegramPlayer;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

function telegramUpdate(string $text, int $userId = 1, string $username = 'user1', int $chatId = -100123): array
{
    return [
        'update_id' => random_int(10_000, 99_999),
        'message' => [
            'message_id' => random_int(10_000, 99_999),
            'from' => [
                'id' => $userId,
                'is_bot' => false,
                'first_name' => 'Test',
                'last_name' => 'User',
                'username' => $username,
            ],
            'chat' => [
                'id' => $chatId,
                'type' => 'supergroup',
                'title' => 'Test Group',
            ],
            'date' => now()->timestamp,
            'text' => $text,
        ],
    ];
}

function telegramCallbackUpdate(string $data, int $roundId, int $userId = 1, string $username = 'user1', int $chatId = -100123): array
{
    return [
        'update_id' => random_int(10_000, 99_999),
        'callback_query' => [
            'id' => 'cbq_'.$roundId.'_'.random_int(100, 999),
            'from' => [
                'id' => $userId,
                'is_bot' => false,
                'first_name' => 'Button',
                'last_name' => 'User',
                'username' => $username,
            ],
            'message' => [
                'message_id' => random_int(10_000, 99_999),
                'chat' => [
                    'id' => $chatId,
                    'type' => 'supergroup',
                    'title' => 'Test Group',
                ],
            ],
            'chat_instance' => 'test-chat-instance',
            'data' => $data,
        ],
    ];
}

test('newbet command creates an open round', function () {
    config()->set('services.telegram.bot_token', 'test-token');
    Http::fake();

    $response = $this->postJson('/telegram/webhook', telegramUpdate('/newbet'));

    $response->assertOk();

    $round = BetRound::query()->first();

    expect($round)->not()->toBeNull();
    expect($round->status)->toBe(BetRound::STATUS_OPEN);
    expect($round->telegram_chat_id)->toBe('-100123');

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/sendMessage')
        && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === "bet:{$round->id}:under_15");
});

test('callback query places and updates bet from inline button', function () {
    config()->set('services.telegram.bot_token', 'test-token');
    Http::fake();

    $this->postJson('/telegram/webhook', telegramUpdate('/newbet'));
    $round = BetRound::query()->firstOrFail();

    $this->postJson('/telegram/webhook', telegramCallbackUpdate("bet:{$round->id}:from_15_to_30", $round->id, 7, 'button_user'));
    $this->postJson('/telegram/webhook', telegramCallbackUpdate("bet:{$round->id}:under_15", $round->id, 7, 'button_user'));

    $player = TelegramPlayer::query()->where('telegram_user_id', '7')->firstOrFail();
    $bet = Bet::query()->where('bet_round_id', $round->id)->where('telegram_player_id', $player->id)->firstOrFail();

    expect($bet->choice)->toBe(BetOutcome::Under15);
    expect($player->total_bets)->toBe(1);

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/answerCallbackQuery'));
});

test('endbath resolves bets and updates leaderboard points', function () {
    Http::fake();
    config()->set('services.telegram.points_per_win', 10);
    Carbon::setTestNow(Carbon::parse('2026-03-18 10:00:00'));

    try {
        $this->postJson('/telegram/webhook', telegramUpdate('/start Luca'));
        $this->postJson('/telegram/webhook', telegramUpdate('/newbet'));

        $this->postJson('/telegram/webhook', telegramUpdate('/bet under15', 1, 'mario'));
        $this->postJson('/telegram/webhook', telegramUpdate('/bet 15-30', 2, 'luca'));

        Carbon::setTestNow(now()->addMinutes(22));
        $this->postJson('/telegram/webhook', telegramUpdate('/stop', 1, 'mario'));
    } finally {
        Carbon::setTestNow();
    }

    $session = BathroomSession::query()->firstOrFail();
    $round = BetRound::query()->firstOrFail();

    expect($session->outcome)->toBe(BetOutcome::From15To30);
    expect($round->status)->toBe(BetRound::STATUS_RESOLVED);
    expect($round->result)->toBe(BetOutcome::From15To30);

    $winner = TelegramPlayer::query()->where('telegram_user_id', '2')->firstOrFail();
    $loser = TelegramPlayer::query()->where('telegram_user_id', '1')->firstOrFail();

    expect($winner->points)->toBe(10);
    expect($winner->wins)->toBe(1);
    expect($winner->total_bets)->toBe(1);
    expect($loser->points)->toBe(0);
    expect($loser->wins)->toBe(0);
    expect($loser->total_bets)->toBe(1);

    $winnerBet = Bet::query()->whereBelongsTo($winner, 'player')->firstOrFail();
    $loserBet = Bet::query()->whereBelongsTo($loser, 'player')->firstOrFail();

    expect($winnerBet->is_winner)->toBeTrue();
    expect($winnerBet->awarded_points)->toBe(10);
    expect($loserBet->is_winner)->toBeFalse();
    expect($loserBet->awarded_points)->toBe(0);
});

test('start command works without a name', function () {
    Http::fake();

    $response = $this->postJson('/telegram/webhook', telegramUpdate('/start'));

    $response->assertOk();

    $session = BathroomSession::query()->firstOrFail();

    expect($session->person_name)->toBe('Persona');
    expect($session->ended_at)->toBeNull();
});

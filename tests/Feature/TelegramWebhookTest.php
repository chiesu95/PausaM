<?php

use App\Enums\BetOutcome;
use App\Enums\DailyBetChoice;
use App\Enums\PeriodicBetType;
use App\Enums\WeeklyBetChoice;
use App\Models\BathroomSession;
use App\Models\Bet;
use App\Models\BetRound;
use App\Models\PeriodicBet;
use App\Models\TelegramChatState;
use App\Models\TelegramLinkCode;
use App\Models\TelegramPlayer;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.telegram.webhook_secret', null);
    config()->set('services.telegram.bet_timezone', 'UTC');
});

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

test('callback query places bet once and prevents further changes', function () {
    config()->set('services.telegram.bot_token', 'test-token');
    Http::fake();

    $this->postJson('/telegram/webhook', telegramUpdate('/newbet'));
    $round = BetRound::query()->firstOrFail();

    $this->postJson('/telegram/webhook', telegramCallbackUpdate("bet:{$round->id}:from_15_to_30", $round->id, 7, 'button_user'));
    $this->postJson('/telegram/webhook', telegramCallbackUpdate("bet:{$round->id}:under_15", $round->id, 7, 'button_user'));

    $player = TelegramPlayer::query()->where('telegram_user_id', '7')->firstOrFail();
    $bet = Bet::query()->where('bet_round_id', $round->id)->where('telegram_player_id', $player->id)->firstOrFail();

    expect($bet->choice)->toBe(BetOutcome::From15To30);
    expect($player->total_bets)->toBe(1);

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/answerCallbackQuery'));
    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/answerCallbackQuery')
        && data_get($request->data(), 'show_alert') === true);

    $sentMessages = collect(Http::recorded())
        ->filter(fn (array $requestResponsePair) => str_contains($requestResponsePair[0]->url(), '/sendMessage'))
        ->count();

    expect($sentMessages)->toBe(2);
});

test('round bet returns all players message when all known players have placed a bet', function () {
    config()->set('services.telegram.bot_token', 'test-token');
    Http::fake();

    $this->postJson('/telegram/webhook', telegramUpdate('/newbet'));
    $firstRound = BetRound::query()->open()->firstOrFail();

    $this->postJson('/telegram/webhook', telegramUpdate('/bet under15', 71, 'p1'));
    $this->postJson('/telegram/webhook', telegramUpdate('/bet 15-30', 72, 'p2'));

    $firstRound->update(['status' => BetRound::STATUS_RESOLVED]);

    $this->postJson('/telegram/webhook', telegramUpdate('/newbet'));
    $this->postJson('/telegram/webhook', telegramUpdate('/bet under15', 71, 'p1'));
    $this->postJson('/telegram/webhook', telegramUpdate('/bet 15-30', 72, 'p2'));

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/sendMessage')
        && data_get($request->data(), 'text') === 'Tutti i giocatori hanno scommesso.');
});

test('endbath resolves bets and updates leaderboard points', function () {
    Http::fake();
    config()->set('services.telegram.bot_token', 'test-token');
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

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/sendMessage')
        && str_contains((string) data_get($request->data(), 'text'), 'Sessione chiusa:')
        && str_contains((string) data_get($request->data(), 'text'), 'Classifica punti:'));
});

test('start command works without a name and opens a round with inline buttons', function () {
    config()->set('services.telegram.bot_token', 'test-token');
    Http::fake();

    $response = $this->postJson('/telegram/webhook', telegramUpdate('/start'));

    $response->assertOk();

    $session = BathroomSession::query()->firstOrFail();
    $round = BetRound::query()->open()->first();

    expect($session->person_name)->toBe('Persona');
    expect($session->ended_at)->toBeNull();
    expect($round)->not()->toBeNull();

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/sendMessage')
        && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === "bet:{$round->id}:under_15");
});

test('start command pins the active round message when telegram returns message id', function () {
    config()->set('services.telegram.bot_token', 'test-token');
    Http::fake([
        'https://api.telegram.org/*/sendMessage' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 701],
        ], 200),
        'https://api.telegram.org/*/pinChatMessage' => Http::response(['ok' => true], 200),
    ]);

    $this->postJson('/telegram/webhook', telegramUpdate('/start'));

    $chatState = TelegramChatState::query()->where('telegram_chat_id', '-100123')->firstOrFail();

    expect($chatState->pinned_round_message_id)->toBe(701);

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/pinChatMessage')
        && data_get($request->data(), 'message_id') === 701);
});

test('stop command unpins the active round message', function () {
    config()->set('services.telegram.bot_token', 'test-token');
    Http::fake([
        'https://api.telegram.org/*/sendMessage' => Http::sequence()
            ->push(['ok' => true, 'result' => ['message_id' => 801]], 200)
            ->push(['ok' => true, 'result' => ['message_id' => 802]], 200),
        'https://api.telegram.org/*/pinChatMessage' => Http::response(['ok' => true], 200),
        'https://api.telegram.org/*/unpinChatMessage' => Http::response(['ok' => true], 200),
    ]);
    Carbon::setTestNow(Carbon::parse('2026-03-18 10:00:00', 'UTC'));

    try {
        $this->postJson('/telegram/webhook', telegramUpdate('/start'));
        Carbon::setTestNow(now()->addMinutes(18));
        $this->postJson('/telegram/webhook', telegramUpdate('/stop'));
    } finally {
        Carbon::setTestNow();
    }

    $chatState = TelegramChatState::query()->where('telegram_chat_id', '-100123')->firstOrFail();

    expect($chatState->pinned_round_message_id)->toBeNull();

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/unpinChatMessage')
        && data_get($request->data(), 'message_id') === 801);
});

test('callback bet on a closed round returns session already concluded message', function () {
    config()->set('services.telegram.bot_token', 'test-token');
    Http::fake();

    $round = BetRound::query()->create([
        'telegram_chat_id' => '-100123',
        'status' => BetRound::STATUS_RESOLVED,
    ]);

    $this->postJson('/telegram/webhook', telegramCallbackUpdate("bet:{$round->id}:under_15", $round->id, 88, 'closed_round_user'));

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/answerCallbackQuery')
        && str_contains((string) data_get($request->data(), 'text'), 'Sessione già conclusa'));
});

test('scheduled daily commands send, pin and unpin daily bet message', function () {
    config()->set('services.telegram.bot_token', 'test-token');
    Carbon::setTestNow(Carbon::parse('2026-03-20 08:30:00', 'UTC'));
    BetRound::query()->create([
        'telegram_chat_id' => '-100555',
        'opened_by_telegram_id' => '123',
    ]);
    Http::fake([
        'https://api.telegram.org/*/sendMessage' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 901],
        ], 200),
        'https://api.telegram.org/*/pinChatMessage' => Http::response(['ok' => true], 200),
        'https://api.telegram.org/*/unpinChatMessage' => Http::response(['ok' => true], 200),
    ]);

    try {
        $this->artisan('telegram:bets:open-daily')->assertExitCode(0);
        $this->artisan('telegram:bets:unpin-daily')->assertExitCode(0);
    } finally {
        Carbon::setTestNow();
    }

    $chatState = TelegramChatState::query()->where('telegram_chat_id', '-100555')->firstOrFail();

    expect($chatState->pinned_daily_message_id)->toBeNull();
    expect($chatState->pinned_daily_for_date)->toBeNull();

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/sendMessage')
        && data_get($request->data(), 'chat_id') === '-100555'
        && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === 'dailybet:under_30');
    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/pinChatMessage')
        && data_get($request->data(), 'message_id') === 901);
    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/unpinChatMessage')
        && data_get($request->data(), 'message_id') === 901);
});

test('link command associates telegram account to a portal user', function () {
    config()->set('services.telegram.bot_token', 'test-token');
    Http::fake();

    $user = User::factory()->create();
    $linkCode = TelegramLinkCode::query()->create([
        'user_id' => $user->id,
        'code' => 'ABCD1234EF',
        'expires_at' => now()->addMinutes(10),
    ]);

    $response = $this->postJson('/telegram/webhook', telegramUpdate('/link ABCD1234EF', 777, 'linked_user'));

    $response->assertOk();

    $player = TelegramPlayer::query()->where('telegram_user_id', '777')->firstOrFail();
    $linkCode->refresh();

    expect($player->user_id)->toBe($user->id);
    expect($linkCode->used_at)->not()->toBeNull();
    expect($linkCode->used_by_telegram_player_id)->toBe($player->id);
});

test('dailybet places a bet before cutoff and rejects bets after 09:30', function () {
    config()->set('services.telegram.bot_token', 'test-token');
    Http::fake();
    Carbon::setTestNow(Carbon::parse('2026-03-20 09:00:00', 'UTC'));

    try {
        $this->postJson('/telegram/webhook', telegramUpdate('/dailybet under1h', 11, 'daily_user'));

        $dailyBet = PeriodicBet::query()->firstOrFail();

        expect($dailyBet->type)->toBe(PeriodicBetType::Daily);
        expect($dailyBet->choice)->toBe('under_1h');
        expect($dailyBet->period_start_date?->toDateString())->toBe('2026-03-20');

        Carbon::setTestNow(Carbon::parse('2026-03-20 09:30:00', 'UTC'));
        $this->postJson('/telegram/webhook', telegramUpdate('/dailybet under30', 12, 'late_user'));
    } finally {
        Carbon::setTestNow();
    }

    expect(PeriodicBet::query()->count())->toBe(1);

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/sendMessage')
        && str_contains((string) data_get($request->data(), 'text'), 'Tempo limite raggiunto per la dailybet, scommetti domani'));
});

test('dailybet and weeklybet commands without options send inline buttons', function () {
    config()->set('services.telegram.bot_token', 'test-token');
    Http::fake();
    Carbon::setTestNow(Carbon::parse('2026-03-16 09:00:00', 'UTC'));

    try {
        $this->postJson('/telegram/webhook', telegramUpdate('/dailybet', 13, 'buttons_user'));
        $this->postJson('/telegram/webhook', telegramUpdate('/weeklybet', 13, 'buttons_user'));
    } finally {
        Carbon::setTestNow();
    }

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/sendMessage')
        && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === 'dailybet:under_30');

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/sendMessage')
        && data_get($request->data(), 'reply_markup.inline_keyboard.0.0.callback_data') === 'weeklybet:under_3h');
});

test('dailybet callback places bet once and prevents changes', function () {
    config()->set('services.telegram.bot_token', 'test-token');
    Http::fake();
    Carbon::setTestNow(Carbon::parse('2026-03-20 09:00:00', 'UTC'));

    try {
        $this->postJson('/telegram/webhook', telegramCallbackUpdate('dailybet:under_1h', 999, 14, 'daily_callback_user'));
        $this->postJson('/telegram/webhook', telegramCallbackUpdate('dailybet:under_30', 999, 14, 'daily_callback_user'));
    } finally {
        Carbon::setTestNow();
    }

    $player = TelegramPlayer::query()->where('telegram_user_id', '14')->firstOrFail();
    $bet = PeriodicBet::query()->where('telegram_player_id', $player->id)->firstOrFail();

    expect($bet->type)->toBe(PeriodicBetType::Daily);
    expect($bet->choice)->toBe('under_1h');
    expect($player->total_bets)->toBe(1);

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/answerCallbackQuery')
        && data_get($request->data(), 'show_alert') === true);
});

test('weeklybet places a bet before monday noon and rejects after cutoff', function () {
    config()->set('services.telegram.bot_token', 'test-token');
    Http::fake();
    Carbon::setTestNow(Carbon::parse('2026-03-16 11:00:00', 'UTC'));

    try {
        $this->postJson('/telegram/webhook', telegramUpdate('/weeklybet under4h', 21, 'week_user'));

        $weeklyBet = PeriodicBet::query()->firstOrFail();

        expect($weeklyBet->type)->toBe(PeriodicBetType::Weekly);
        expect($weeklyBet->choice)->toBe('under_4h');
        expect($weeklyBet->period_start_date?->toDateString())->toBe('2026-03-16');

        Carbon::setTestNow(Carbon::parse('2026-03-16 12:00:00', 'UTC'));
        $this->postJson('/telegram/webhook', telegramUpdate('/weeklybet over6h', 22, 'week_late'));
    } finally {
        Carbon::setTestNow();
    }

    expect(PeriodicBet::query()->count())->toBe(1);

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/sendMessage')
        && str_contains((string) data_get($request->data(), 'text'), 'Tempo limite raggiunto per la weeklybet, scommetti la prossima settimana'));
});

test('daily and weekly periodic bets use dedicated points config', function () {
    config()->set('services.telegram.bot_token', 'test-token');
    config()->set('services.telegram.points_per_win', 5);
    config()->set('services.telegram.points_per_win_daily', 10);
    config()->set('services.telegram.points_per_win_weekly', 15);
    Http::fake();
    Carbon::setTestNow(Carbon::parse('2026-03-16 09:00:00', 'UTC'));

    try {
        $this->postJson('/telegram/webhook', telegramUpdate('/dailybet under1h', 23, 'points_user'));
        $this->postJson('/telegram/webhook', telegramUpdate('/weeklybet under3h', 23, 'points_user'));

        BathroomSession::query()->create([
            'person_name' => 'A',
            'started_at' => Carbon::parse('2026-03-16 09:00:00', 'UTC'),
            'ended_at' => Carbon::parse('2026-03-16 09:50:00', 'UTC'),
            'duration_minutes' => 50,
        ]);
        BathroomSession::query()->create([
            'person_name' => 'B',
            'started_at' => Carbon::parse('2026-03-18 10:00:00', 'UTC'),
            'ended_at' => Carbon::parse('2026-03-18 12:00:00', 'UTC'),
            'duration_minutes' => 120,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-17 10:00:00', 'UTC'));
        $this->postJson('/telegram/webhook', telegramUpdate('/dailytotal', 23, 'points_user'));

        Carbon::setTestNow(Carbon::parse('2026-03-23 10:00:00', 'UTC'));
        $this->postJson('/telegram/webhook', telegramUpdate('/weeklytotal', 23, 'points_user'));
    } finally {
        Carbon::setTestNow();
    }

    $player = TelegramPlayer::query()->where('telegram_user_id', '23')->firstOrFail();
    $dailyBet = PeriodicBet::query()->where('type', PeriodicBetType::Daily)->firstOrFail();
    $weeklyBet = PeriodicBet::query()->where('type', PeriodicBetType::Weekly)->firstOrFail();

    expect($dailyBet->is_winner)->toBeTrue();
    expect($dailyBet->awarded_points)->toBe(10);
    expect($weeklyBet->is_winner)->toBeTrue();
    expect($weeklyBet->awarded_points)->toBe(15);

    expect($player->points)->toBe(25);
    expect($player->wins)->toBe(2);
    expect($player->total_bets)->toBe(2);
});

test('daily and weekly bet ranges are inclusive on upper boundaries', function () {
    expect(DailyBetChoice::Under30->isWinning(29.99))->toBeTrue();
    expect(DailyBetChoice::Under1Hour->isWinning(29.99))->toBeFalse();
    expect(DailyBetChoice::Under1Hour->isWinning(45.0))->toBeTrue();
    expect(DailyBetChoice::Under1Hour30->isWinning(45.0))->toBeFalse();
    expect(DailyBetChoice::Under1Hour30->isWinning(75.0))->toBeTrue();
    expect(DailyBetChoice::Over1Hour30->isWinning(75.0))->toBeFalse();
    expect(DailyBetChoice::Under30->isWinning(30.0))->toBeTrue();
    expect(DailyBetChoice::Under1Hour->isWinning(30.0))->toBeFalse();

    expect(WeeklyBetChoice::Under3Hours->isWinning(170.0))->toBeTrue();
    expect(WeeklyBetChoice::Under4Hours->isWinning(170.0))->toBeFalse();
    expect(WeeklyBetChoice::Under4Hours->isWinning(200.0))->toBeTrue();
    expect(WeeklyBetChoice::Under6Hours->isWinning(200.0))->toBeFalse();
    expect(WeeklyBetChoice::Under6Hours->isWinning(260.0))->toBeTrue();
    expect(WeeklyBetChoice::Over6Hours->isWinning(260.0))->toBeFalse();
    expect(WeeklyBetChoice::Under3Hours->isWinning(180.0))->toBeTrue();
    expect(WeeklyBetChoice::Under4Hours->isWinning(180.0))->toBeFalse();
});

test('dailytotal command returns summed time for current day', function () {
    config()->set('services.telegram.bot_token', 'test-token');
    Http::fake();
    Carbon::setTestNow(Carbon::parse('2026-03-20 18:00:00', 'UTC'));

    try {
        BathroomSession::query()->create([
            'person_name' => 'A',
            'started_at' => Carbon::parse('2026-03-20 08:00:00', 'UTC'),
            'ended_at' => Carbon::parse('2026-03-20 08:25:00', 'UTC'),
            'duration_minutes' => 25,
        ]);
        BathroomSession::query()->create([
            'person_name' => 'B',
            'started_at' => Carbon::parse('2026-03-20 12:00:00', 'UTC'),
            'ended_at' => Carbon::parse('2026-03-20 12:50:00', 'UTC'),
            'duration_minutes' => 50,
        ]);
        BathroomSession::query()->create([
            'person_name' => 'C',
            'started_at' => Carbon::parse('2026-03-19 20:00:00', 'UTC'),
            'ended_at' => Carbon::parse('2026-03-19 20:20:00', 'UTC'),
            'duration_minutes' => 20,
        ]);

        $this->postJson('/telegram/webhook', telegramUpdate('/dailytotal', 31, 'tot_user'));
    } finally {
        Carbon::setTestNow();
    }

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/sendMessage')
        && str_contains((string) data_get($request->data(), 'text'), 'Totale giornata 20/03/2026: 75.00 minuti (1h 15m).'));
});

test('weeklytotal command returns current and previous monday-sunday totals', function () {
    config()->set('services.telegram.bot_token', 'test-token');
    Http::fake();
    Carbon::setTestNow(Carbon::parse('2026-03-18 10:00:00', 'UTC'));

    try {
        BathroomSession::query()->create([
            'person_name' => 'A',
            'started_at' => Carbon::parse('2026-03-16 08:00:00', 'UTC'),
            'ended_at' => Carbon::parse('2026-03-16 08:30:00', 'UTC'),
            'duration_minutes' => 30,
        ]);
        BathroomSession::query()->create([
            'person_name' => 'B',
            'started_at' => Carbon::parse('2026-03-20 09:00:00', 'UTC'),
            'ended_at' => Carbon::parse('2026-03-20 09:45:00', 'UTC'),
            'duration_minutes' => 45,
        ]);
        BathroomSession::query()->create([
            'person_name' => 'C',
            'started_at' => Carbon::parse('2026-03-10 11:00:00', 'UTC'),
            'ended_at' => Carbon::parse('2026-03-10 12:00:00', 'UTC'),
            'duration_minutes' => 60,
        ]);
        BathroomSession::query()->create([
            'person_name' => 'D',
            'started_at' => Carbon::parse('2026-03-15 18:00:00', 'UTC'),
            'ended_at' => Carbon::parse('2026-03-15 18:20:00', 'UTC'),
            'duration_minutes' => 20,
        ]);

        $this->postJson('/telegram/webhook', telegramUpdate('/weeklytotal', 32, 'week_total_user'));
    } finally {
        Carbon::setTestNow();
    }

    Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/sendMessage')
        && str_contains((string) data_get($request->data(), 'text'), 'Settimana corrente (16/03/2026 - 22/03/2026): 75.00 minuti (1h 15m).')
        && str_contains((string) data_get($request->data(), 'text'), 'Settimana precedente (09/03/2026 - 15/03/2026): 80.00 minuti (1h 20m).'));
});

test('periodic bets are resolved automatically when period has ended', function () {
    config()->set('services.telegram.bot_token', 'test-token');
    Http::fake();
    Carbon::setTestNow(Carbon::parse('2026-03-19 08:00:00', 'UTC'));

    try {
        $this->postJson('/telegram/webhook', telegramUpdate('/dailybet under1h', 41, 'resolve_user'));

        BathroomSession::query()->create([
            'person_name' => 'A',
            'started_at' => Carbon::parse('2026-03-19 17:00:00', 'UTC'),
            'ended_at' => Carbon::parse('2026-03-19 18:10:00', 'UTC'),
            'duration_minutes' => 70,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-20 10:00:00', 'UTC'));
        $this->postJson('/telegram/webhook', telegramUpdate('/dailytotal', 41, 'resolve_user'));
    } finally {
        Carbon::setTestNow();
    }

    $periodicBet = PeriodicBet::query()->firstOrFail();

    expect($periodicBet->resolved_at)->not()->toBeNull();
    expect($periodicBet->resolved_total_minutes)->toBe(70.0);
    expect($periodicBet->is_winner)->toBeFalse();
});

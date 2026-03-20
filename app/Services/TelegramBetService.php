<?php

namespace App\Services;

use App\Enums\BetOutcome;
use App\Enums\DailyBetChoice;
use App\Enums\PeriodicBetType;
use App\Enums\WeeklyBetChoice;
use App\Models\BathroomSession;
use App\Models\Bet;
use App\Models\BetRound;
use App\Models\PeriodicBet;
use App\Models\TelegramChatState;
use App\Models\TelegramPlayer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class TelegramBetService
{
    public const BET_RESULT_PLACED = 'placed';

    public const BET_RESULT_ALREADY_PLACED = 'already_placed';

    public const BET_RESULT_ERROR = 'error';

    public const STOP_RESULT_RESOLVED = 'resolved';

    public const STOP_RESULT_NO_ACTIVE_SESSION = 'no_active_session';

    public function openRound(string $chatId, ?string $openedByTelegramId): string
    {
        $activeRound = BetRound::query()
            ->open()
            ->where('telegram_chat_id', $chatId)
            ->latest('id')
            ->first();

        if ($activeRound) {
            if ($this->allKnownPlayersPlacedRound($activeRound)) {
                return 'Tutti i giocatori hanno scommesso.';
            }

            return 'C\'e gia una scommessa aperta. Usa i pulsanti del messaggio attivo oppure /stop per chiuderla.';
        }

        BetRound::query()->create([
            'telegram_chat_id' => $chatId,
            'opened_by_telegram_id' => $openedByTelegramId,
        ]);

        return implode("\n", [
            'Nuova scommessa aperta.',
            'Esiti possibili:',
            '- under 15',
            '- from 15 to 30',
            '- from 30 to 45',
            '- over 45',
            'Punta con i pulsanti qui sotto.',
            'Shortcut testuali opzionali: under15, 15-30, 30-45, over45',
            'Tracciamento: /start [nome-opzionale] e /stop',
        ]);
    }

    public function placeDailyBet(string $chatId, array $telegramUser, string $rawChoice): string
    {
        return $this->placeDailyBetResult($chatId, $telegramUser, $rawChoice)['message'];
    }

    public function placeWeeklyBet(string $chatId, array $telegramUser, string $rawChoice): string
    {
        return $this->placeWeeklyBetResult($chatId, $telegramUser, $rawChoice)['message'];
    }

    /**
     * @return array{message: string, status: string}
     */
    public function placeDailyBetResult(string $chatId, array $telegramUser, string $rawChoice): array
    {
        $this->resolveExpiredPeriodicBetsForChat($chatId);

        $choice = DailyBetChoice::fromInput($rawChoice);

        if (! $choice) {
            return [
                'message' => 'Opzione non valida. Usa: under30, under1h, under1h30, over1h30.',
                'status' => self::BET_RESULT_ERROR,
            ];
        }

        return $this->placePeriodicBet(
            $chatId,
            $telegramUser,
            PeriodicBetType::Daily,
            $choice->value,
        );
    }

    /**
     * @return array{message: string, status: string}
     */
    public function placeWeeklyBetResult(string $chatId, array $telegramUser, string $rawChoice): array
    {
        $this->resolveExpiredPeriodicBetsForChat($chatId);

        $choice = WeeklyBetChoice::fromInput($rawChoice);

        if (! $choice) {
            return [
                'message' => 'Opzione non valida. Usa: under3h, under4h, under5h, over6h.',
                'status' => self::BET_RESULT_ERROR,
            ];
        }

        return $this->placePeriodicBet(
            $chatId,
            $telegramUser,
            PeriodicBetType::Weekly,
            $choice->value,
        );
    }

    /**
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    public function dailyBetInlineKeyboard(): array
    {
        return [
            [
                ['text' => 'Under 30m', 'callback_data' => 'dailybet:under_30'],
                ['text' => 'Under 1h', 'callback_data' => 'dailybet:under_1h'],
            ],
            [
                ['text' => 'Under 1h30', 'callback_data' => 'dailybet:under_1h30'],
                ['text' => 'Over 1h30', 'callback_data' => 'dailybet:over_1h30'],
            ],
        ];
    }

    /**
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    public function weeklyBetInlineKeyboard(): array
    {
        return [
            [
                ['text' => 'Under 3h', 'callback_data' => 'weeklybet:under_3h'],
                ['text' => 'Under 4h', 'callback_data' => 'weeklybet:under_4h'],
            ],
            [
                ['text' => 'Under 5h', 'callback_data' => 'weeklybet:under_5h'],
                ['text' => 'Over 6h', 'callback_data' => 'weeklybet:over_6h'],
            ],
        ];
    }

    public function dailyBetPromptMessage(): string
    {
        return 'Scegli la dailybet (valida solo prima delle 09:30):';
    }

    public function weeklyBetPromptMessage(): string
    {
        return 'Scegli la weeklybet (valida entro lunedi alle 12:00):';
    }

    /**
     * @return array{message: string, show_keyboard: bool}
     */
    public function dailyBetPromptStateForChat(string $chatId): array
    {
        $nowLocal = $this->nowInBetTimezone();
        [$periodStart] = $this->periodBoundsForType(PeriodicBetType::Daily, $nowLocal);
        $cutoff = $this->betCutoffForType(PeriodicBetType::Daily, $periodStart);

        if ($nowLocal->greaterThanOrEqualTo($cutoff)) {
            return [
                'message' => 'Tempo limite raggiunto per la dailybet, scommetti domani',
                'show_keyboard' => false,
            ];
        }

        if ($this->allKnownPlayersPlacedPeriodicBet($chatId, PeriodicBetType::Daily, $periodStart->toDateString())) {
            return [
                'message' => 'Tutti i giocatori hanno scommesso.',
                'show_keyboard' => false,
            ];
        }

        return [
            'message' => $this->dailyBetPromptMessage(),
            'show_keyboard' => true,
        ];
    }

    /**
     * @return array{message: string, show_keyboard: bool}
     */
    public function weeklyBetPromptStateForChat(string $chatId): array
    {
        $nowLocal = $this->nowInBetTimezone();
        [$periodStart] = $this->periodBoundsForType(PeriodicBetType::Weekly, $nowLocal);
        $cutoff = $this->betCutoffForType(PeriodicBetType::Weekly, $periodStart);

        if ($nowLocal->greaterThanOrEqualTo($cutoff)) {
            return [
                'message' => 'Tempo limite raggiunto per la weeklybet, scommetti la prossima settimana',
                'show_keyboard' => false,
            ];
        }

        if ($this->allKnownPlayersPlacedPeriodicBet($chatId, PeriodicBetType::Weekly, $periodStart->toDateString())) {
            return [
                'message' => 'Tutti i giocatori hanno scommesso.',
                'show_keyboard' => false,
            ];
        }

        return [
            'message' => $this->weeklyBetPromptMessage(),
            'show_keyboard' => true,
        ];
    }

    /**
     * @return list<string>
     */
    public function scheduledChatIds(): array
    {
        $configuredChatIds = config('services.telegram.scheduled_chat_ids', []);

        if (is_string($configuredChatIds)) {
            $configuredChatIds = array_filter(
                array_map('trim', explode(',', $configuredChatIds)),
            );
        }

        if (! is_array($configuredChatIds)) {
            $configuredChatIds = [];
        }

        $fromRounds = BetRound::query()
            ->select('telegram_chat_id')
            ->distinct()
            ->pluck('telegram_chat_id')
            ->all();

        $fromPeriodicBets = PeriodicBet::query()
            ->select('telegram_chat_id')
            ->distinct()
            ->pluck('telegram_chat_id')
            ->all();

        $fromChatStates = TelegramChatState::query()
            ->select('telegram_chat_id')
            ->distinct()
            ->pluck('telegram_chat_id')
            ->all();

        return array_values(
            array_unique(
                array_filter(
                    array_map('strval', array_merge(
                        $configuredChatIds,
                        $fromRounds,
                        $fromPeriodicBets,
                        $fromChatStates,
                    )),
                    static fn (string $chatId): bool => $chatId !== '',
                ),
            ),
        );
    }

    public function pinRoundMessageForChat(string $chatId, int $messageId, TelegramBotService $botService): void
    {
        if (! $botService->pinMessage($chatId, $messageId)) {
            return;
        }

        $state = $this->chatStateForChat($chatId);
        $previousMessageId = $state->pinned_round_message_id;

        $state->update([
            'pinned_round_message_id' => $messageId,
        ]);

        if ($previousMessageId && $previousMessageId !== $messageId) {
            $botService->unpinMessage($chatId, $previousMessageId);
        }
    }

    public function unpinRoundMessageForChat(string $chatId, TelegramBotService $botService): void
    {
        $state = TelegramChatState::query()
            ->where('telegram_chat_id', $chatId)
            ->first();

        if (! $state || ! $state->pinned_round_message_id) {
            return;
        }

        $botService->unpinMessage($chatId, (int) $state->pinned_round_message_id);

        $state->update([
            'pinned_round_message_id' => null,
        ]);
    }

    public function pinDailyMessageForCurrentDate(string $chatId, int $messageId, TelegramBotService $botService): void
    {
        if (! $botService->pinMessage($chatId, $messageId)) {
            return;
        }

        $state = $this->chatStateForChat($chatId);
        $previousMessageId = $state->pinned_daily_message_id;

        $state->update([
            'pinned_daily_message_id' => $messageId,
            'pinned_daily_for_date' => $this->nowInBetTimezone()->toDateString(),
        ]);

        if ($previousMessageId && $previousMessageId !== $messageId) {
            $botService->unpinMessage($chatId, $previousMessageId);
        }
    }

    public function unpinDailyMessageIfExpired(string $chatId, TelegramBotService $botService): void
    {
        $state = TelegramChatState::query()
            ->where('telegram_chat_id', $chatId)
            ->first();

        if (! $state || ! $state->pinned_daily_message_id) {
            return;
        }

        $today = $this->nowInBetTimezone()->toDateString();
        $pinnedDate = $state->pinned_daily_for_date?->toDateString();

        if ($pinnedDate !== null && $pinnedDate > $today) {
            return;
        }

        $botService->unpinMessage($chatId, (int) $state->pinned_daily_message_id);

        $state->update([
            'pinned_daily_message_id' => null,
            'pinned_daily_for_date' => null,
        ]);
    }

    public function pinWeeklyMessageForCurrentWeek(string $chatId, int $messageId, TelegramBotService $botService): void
    {
        if (! $botService->pinMessage($chatId, $messageId)) {
            return;
        }

        $state = $this->chatStateForChat($chatId);
        $previousMessageId = $state->pinned_weekly_message_id;
        $currentWeekStart = $this->nowInBetTimezone()->startOfWeek(CarbonInterface::MONDAY)->toDateString();

        $state->update([
            'pinned_weekly_message_id' => $messageId,
            'pinned_weekly_for_week_start_date' => $currentWeekStart,
        ]);

        if ($previousMessageId && $previousMessageId !== $messageId) {
            $botService->unpinMessage($chatId, $previousMessageId);
        }
    }

    public function unpinWeeklyMessageIfExpired(string $chatId, TelegramBotService $botService): void
    {
        $state = TelegramChatState::query()
            ->where('telegram_chat_id', $chatId)
            ->first();

        if (! $state || ! $state->pinned_weekly_message_id) {
            return;
        }

        $currentWeekStart = $this->nowInBetTimezone()->startOfWeek(CarbonInterface::MONDAY)->toDateString();
        $pinnedWeekStart = $state->pinned_weekly_for_week_start_date?->toDateString();

        if ($pinnedWeekStart !== null && $pinnedWeekStart > $currentWeekStart) {
            return;
        }

        $botService->unpinMessage($chatId, (int) $state->pinned_weekly_message_id);

        $state->update([
            'pinned_weekly_message_id' => null,
            'pinned_weekly_for_week_start_date' => null,
        ]);
    }

    public function dailyTotalMessage(string $chatId): string
    {
        $this->resolveExpiredPeriodicBetsForChat($chatId);

        $nowLocal = $this->nowInBetTimezone();
        [$periodStart, $periodEnd] = $this->periodBoundsForType(PeriodicBetType::Daily, $nowLocal);
        $totalMinutes = $this->totalMinutesBetween($periodStart, $periodEnd);

        return sprintf(
            'Totale giornata %s: %s.',
            $periodStart->format('d/m/Y'),
            $this->formatMinutes($totalMinutes),
        );
    }

    public function weeklyTotalMessage(string $chatId): string
    {
        $this->resolveExpiredPeriodicBetsForChat($chatId);

        $nowLocal = $this->nowInBetTimezone();
        [$currentWeekStart, $currentWeekEnd] = $this->periodBoundsForType(PeriodicBetType::Weekly, $nowLocal);
        $previousWeekStart = $currentWeekStart->subWeek();
        $previousWeekEnd = $currentWeekStart;

        $currentWeekTotal = $this->totalMinutesBetween($currentWeekStart, $currentWeekEnd);
        $previousWeekTotal = $this->totalMinutesBetween($previousWeekStart, $previousWeekEnd);

        return implode("\n", [
            sprintf(
                'Settimana corrente (%s - %s): %s.',
                $currentWeekStart->format('d/m/Y'),
                $currentWeekEnd->subDay()->format('d/m/Y'),
                $this->formatMinutes($currentWeekTotal),
            ),
            sprintf(
                'Settimana precedente (%s - %s): %s.',
                $previousWeekStart->format('d/m/Y'),
                $previousWeekEnd->subDay()->format('d/m/Y'),
                $this->formatMinutes($previousWeekTotal),
            ),
        ]);
    }

    /**
     * @param  array{id?: int|string, username?: string, first_name?: string, last_name?: string}  $telegramUser
     */
    public function placeBet(string $chatId, array $telegramUser, string $rawChoice): string
    {
        $round = BetRound::query()
            ->open()
            ->where('telegram_chat_id', $chatId)
            ->latest('id')
            ->first();

        if (! $round) {
            return 'Nessuna scommessa aperta. Avvia con /start.';
        }

        return $this->placeBetForRoundResult($chatId, $telegramUser, $round->id, $rawChoice)['message'];
    }

    /**
     * @param  array{id?: int|string, username?: string, first_name?: string, last_name?: string}  $telegramUser
     */
    public function placeBetForRound(string $chatId, array $telegramUser, int $roundId, string $rawChoice): string
    {
        return $this->placeBetForRoundResult($chatId, $telegramUser, $roundId, $rawChoice)['message'];
    }

    /**
     * @param  array{id?: int|string, username?: string, first_name?: string, last_name?: string}  $telegramUser
     * @return array{message: string, status: string}
     */
    public function placeBetForRoundResult(string $chatId, array $telegramUser, int $roundId, string $rawChoice): array
    {
        if (! isset($telegramUser['id'])) {
            return [
                'message' => 'Impossibile identificare l\'utente Telegram per registrare la puntata.',
                'status' => self::BET_RESULT_ERROR,
            ];
        }

        $choice = $this->resolveChoice($rawChoice);

        if (! $choice) {
            return [
                'message' => 'Opzione non valida. Usa: under15, 15-30, 30-45, over45 (oppure from 15 to 30, from 30 to 45).',
                'status' => self::BET_RESULT_ERROR,
            ];
        }

        $round = BetRound::query()
            ->where('id', $roundId)
            ->where('telegram_chat_id', $chatId)
            ->first();

        if (! $round) {
            return [
                'message' => 'Round non trovato per questa chat.',
                'status' => self::BET_RESULT_ERROR,
            ];
        }

        if ($round->status !== BetRound::STATUS_OPEN) {
            return [
                'message' => 'Sessione già conclusa.',
                'status' => self::BET_RESULT_ERROR,
            ];
        }

        $player = $this->upsertPlayer($telegramUser);

        $bet = Bet::query()
            ->where('bet_round_id', $round->id)
            ->where('telegram_player_id', $player->id)
            ->first();

        if ($bet) {
            if ($this->allKnownPlayersPlacedRound($round)) {
                return [
                    'message' => 'Tutti i giocatori hanno scommesso.',
                    'status' => self::BET_RESULT_ALREADY_PLACED,
                ];
            }

            return [
                'message' => sprintf(
                    '%s, hai gia puntato su: %s. In questo round non puoi modificare la selezione.',
                    $this->displayName($player),
                    $bet->choice->label(),
                ),
                'status' => self::BET_RESULT_ALREADY_PLACED,
            ];
        }

        Bet::query()->create([
            'bet_round_id' => $round->id,
            'telegram_player_id' => $player->id,
            'choice' => $choice,
        ]);

        $player->increment('total_bets');

        if ($this->allKnownPlayersPlacedRound($round)) {
            return [
                'message' => 'Tutti i giocatori hanno scommesso.',
                'status' => self::BET_RESULT_PLACED,
            ];
        }

        return [
            'message' => sprintf('%s ha puntato su: %s.', $this->displayName($player), $choice->label()),
            'status' => self::BET_RESULT_PLACED,
        ];
    }

    public function openRoundForChat(string $chatId): ?BetRound
    {
        return BetRound::query()
            ->open()
            ->where('telegram_chat_id', $chatId)
            ->latest('id')
            ->first();
    }

    /**
     * @return array<int, array<int, array{text: string, callback_data: string}>>
     */
    public function roundInlineKeyboard(int $roundId): array
    {
        return [
            [
                ['text' => 'Under 15', 'callback_data' => "bet:{$roundId}:under_15"],
                ['text' => '15-30', 'callback_data' => "bet:{$roundId}:from_15_to_30"],
            ],
            [
                ['text' => '30-45', 'callback_data' => "bet:{$roundId}:from_30_to_45"],
                ['text' => 'Over 45', 'callback_data' => "bet:{$roundId}:over_45"],
            ],
        ];
    }

    /**
     * @return array{message: string, started: bool}
     */
    public function startBathroomSession(?string $personName = null): array
    {
        $activeSession = BathroomSession::query()->active()->latest('id')->first();

        if ($activeSession) {
            return [
                'message' => sprintf(
                    'C\'e gia una sessione attiva per %s iniziata alle %s.',
                    $activeSession->person_name,
                    $activeSession->started_at->format('H:i'),
                ),
                'started' => false,
            ];
        }

        $session = BathroomSession::query()->create([
            'person_name' => $personName ?: 'Persona',
            'started_at' => now(),
        ]);

        return [
            'message' => sprintf(
                'Sessione bagno avviata per %s alle %s.',
                $session->person_name,
                $session->started_at->format('H:i'),
            ),
            'started' => true,
        ];
    }

    public function endBathroomSessionAndResolve(string $chatId): string
    {
        return $this->endBathroomSessionAndResolveResult($chatId)['message'];
    }

    /**
     * @return array{message: string, status: string}
     */
    public function endBathroomSessionAndResolveResult(string $chatId): array
    {
        return DB::transaction(function () use ($chatId) {
            $session = BathroomSession::query()
                ->active()
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $session) {
                return [
                    'message' => 'Non c\'e nessuna sessione bagno attiva. Usa /start.',
                    'status' => self::STOP_RESULT_NO_ACTIVE_SESSION,
                ];
            }

            $endedAt = now();
            $durationMinutes = $session->started_at->diffInSeconds($endedAt) / 60;
            $result = BetOutcome::fromDurationInMinutes($durationMinutes);

            $session->update([
                'ended_at' => $endedAt,
                'duration_minutes' => round($durationMinutes, 2),
                'outcome' => $result,
            ]);

            $round = BetRound::query()
                ->open()
                ->where('telegram_chat_id', $chatId)
                ->with(['bets.player'])
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $round) {
                return [
                    'message' => sprintf(
                        'Sessione chiusa: %.2f minuti (%s). Nessuna scommessa aperta da risolvere.',
                        $durationMinutes,
                        $result->label(),
                    ),
                    'status' => self::STOP_RESULT_RESOLVED,
                ];
            }

            $round->update([
                'bathroom_session_id' => $session->id,
                'status' => BetRound::STATUS_RESOLVED,
                'result' => $result,
                'resolved_at' => $endedAt,
            ]);

            $pointsPerWin = (int) config('services.telegram.points_per_win', 10);
            $winners = [];

            foreach ($round->bets as $bet) {
                $isWinner = $bet->choice === $result;
                $awardedPoints = $isWinner ? $pointsPerWin : 0;

                $bet->update([
                    'is_winner' => $isWinner,
                    'awarded_points' => $awardedPoints,
                ]);

                if (! $isWinner) {
                    continue;
                }

                $bet->player->increment('points', $awardedPoints);
                $bet->player->increment('wins');
                $winners[] = $this->displayName($bet->player);
            }

            $summary = sprintf(
                'Sessione chiusa: %.2f minuti (%s).',
                $durationMinutes,
                $result->label(),
            );

            if ($round->bets->isEmpty()) {
                return [
                    'message' => $summary."\n".'Nessuna puntata registrata.',
                    'status' => self::STOP_RESULT_RESOLVED,
                ];
            }

            if ($winners === []) {
                return [
                    'message' => $summary."\n".'Nessun vincitore in questo round.',
                    'status' => self::STOP_RESULT_RESOLVED,
                ];
            }

            return [
                'message' => $summary."\n".sprintf(
                    'Vincitori (+%d punti): %s',
                    $pointsPerWin,
                    implode(', ', $winners),
                ),
                'status' => self::STOP_RESULT_RESOLVED,
            ];
        });
    }

    public function leaderboard(int $limit = 10): string
    {
        $rows = TelegramPlayer::query()
            ->orderByDesc('points')
            ->orderByDesc('wins')
            ->orderByDesc('total_bets')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return 'Classifica vuota. Avvia una sessione con /start.';
        }

        $lines = ['Classifica punti:'];

        foreach ($rows as $index => $player) {
            $lines[] = sprintf(
                '%d. %s - %d pt (%d vinte / %d puntate)',
                $index + 1,
                $this->displayName($player),
                $player->points,
                $player->wins,
                $player->total_bets,
            );
        }

        return implode("\n", $lines);
    }

    public function help(): string
    {
        return implode("\n", [
            'Comandi disponibili:',
            '/start [nome] - avvia il timer bagno (nome opzionale) e apre la scommessa',
            '/dailybet <under30|under1h|under1h30|over1h30> - bet sul totale giornaliero (entro le 09:30)',
            '/weeklybet <under3h|under4h|under5h|over6h> - bet sul totale settimanale (entro lunedi 12:00)',
            'Le puntate del round si fanno dai pulsanti del messaggio aperto con /start.',
            '/dailytotal - totale tempo bagno della giornata corrente',
            '/weeklytotal - totale settimana corrente e precedente (lunedi-domenica)',
            '/stop - chiude timer e risolve la scommessa',
            '/link <codice> - collega account Telegram a utente portale',
            '/leaderboard - mostra la classifica punti',
        ]);
    }

    public function resolveExpiredPeriodicBetsForChat(string $chatId): void
    {
        $nowLocal = $this->nowInBetTimezone();

        $pendingBets = PeriodicBet::query()
            ->where('telegram_chat_id', $chatId)
            ->whereNull('resolved_at')
            ->get()
            ->groupBy(fn (PeriodicBet $bet) => $bet->type->value.'|'.$bet->period_start_date?->toDateString());

        foreach ($pendingBets as $betsForPeriod) {
            $firstBet = $betsForPeriod->first();

            if (! $firstBet || ! $firstBet->period_start_date) {
                continue;
            }

            $periodStart = CarbonImmutable::parse(
                $firstBet->period_start_date->toDateString(),
                $this->betTimezone(),
            )->startOfDay();

            [$start, $end] = $this->periodBoundsFromStart($firstBet->type, $periodStart);

            if ($end->greaterThan($nowLocal)) {
                continue;
            }

            $totalMinutes = $this->totalMinutesBetween($start, $end);
            $resolvedAt = now();
            $pointsPerWin = $this->pointsPerWinForPeriodicType($firstBet->type);

            foreach ($betsForPeriod as $bet) {
                $isWinner = $this->periodicBetWins($bet, $totalMinutes);
                $awardedPoints = $isWinner ? $pointsPerWin : 0;

                $bet->update([
                    'resolved_at' => $resolvedAt,
                    'resolved_total_minutes' => round($totalMinutes, 2),
                    'is_winner' => $isWinner,
                    'awarded_points' => $awardedPoints,
                ]);

                if (! $isWinner) {
                    continue;
                }

                $bet->player()->increment('points', $awardedPoints);
                $bet->player()->increment('wins');
            }
        }
    }

    /**
     * @param  array{id?: int|string, username?: string, first_name?: string, last_name?: string}  $telegramUser
     */
    protected function upsertPlayer(array $telegramUser): TelegramPlayer
    {
        $telegramUserId = (string) ($telegramUser['id'] ?? '');

        $fullName = trim(
            implode(
                ' ',
                array_filter([
                    (string) ($telegramUser['first_name'] ?? ''),
                    (string) ($telegramUser['last_name'] ?? ''),
                ]),
            ),
        );

        $player = TelegramPlayer::query()->firstOrCreate(
            ['telegram_user_id' => $telegramUserId],
            [
                'username' => $telegramUser['username'] ?? null,
                'full_name' => $fullName !== '' ? $fullName : 'Utente',
                'last_seen_at' => now(),
            ],
        );

        $player->fill([
            'username' => $telegramUser['username'] ?? $player->username,
            'full_name' => $fullName !== '' ? $fullName : $player->full_name,
            'last_seen_at' => now(),
        ]);
        $player->save();

        return $player;
    }

    protected function displayName(TelegramPlayer $player): string
    {
        if ($player->username) {
            return '@'.$player->username;
        }

        return $player->full_name;
    }

    protected function resolveChoice(string $rawChoice): ?BetOutcome
    {
        return BetOutcome::tryFrom($rawChoice) ?? BetOutcome::fromInput($rawChoice);
    }

    protected function placePeriodicBet(
        string $chatId,
        array $telegramUser,
        PeriodicBetType $type,
        string $choiceValue
    ): array {
        if (! isset($telegramUser['id'])) {
            return [
                'message' => 'Impossibile identificare l\'utente Telegram per registrare la puntata.',
                'status' => self::BET_RESULT_ERROR,
            ];
        }

        $nowLocal = $this->nowInBetTimezone();
        [$periodStart, $periodEnd] = $this->periodBoundsForType($type, $nowLocal);
        $cutoff = $this->betCutoffForType($type, $periodStart);

        if ($nowLocal->greaterThanOrEqualTo($cutoff)) {
            return [
                'message' => match ($type) {
                    PeriodicBetType::Daily => 'Tempo limite raggiunto per la dailybet, scommetti domani',
                    PeriodicBetType::Weekly => 'Tempo limite raggiunto per la weeklybet, scommetti la prossima settimana',
                },
                'status' => self::BET_RESULT_ERROR,
            ];
        }

        $player = $this->upsertPlayer($telegramUser);
        $periodDate = $periodStart->toDateString();

        $existingBet = PeriodicBet::query()
            ->where('telegram_chat_id', $chatId)
            ->where('telegram_player_id', $player->id)
            ->where('type', $type)
            ->whereDate('period_start_date', $periodDate)
            ->first();

        if ($existingBet) {
            if ($this->allKnownPlayersPlacedPeriodicBet($chatId, $type, $periodDate)) {
                return [
                    'message' => 'Tutti i giocatori hanno scommesso.',
                    'status' => self::BET_RESULT_ALREADY_PLACED,
                ];
            }

            return [
                'message' => sprintf(
                    '%s, hai gia piazzato la bet %s su: %s.',
                    $this->displayName($player),
                    $type->label(),
                    $this->periodicChoiceLabel($type, $existingBet->choice),
                ),
                'status' => self::BET_RESULT_ALREADY_PLACED,
            ];
        }

        PeriodicBet::query()->create([
            'telegram_chat_id' => $chatId,
            'telegram_player_id' => $player->id,
            'type' => $type,
            'period_start_date' => $periodDate,
            'choice' => $choiceValue,
        ]);
        $player->increment('total_bets');

        if ($this->allKnownPlayersPlacedPeriodicBet($chatId, $type, $periodDate)) {
            return [
                'message' => 'Tutti i giocatori hanno scommesso.',
                'status' => self::BET_RESULT_PLACED,
            ];
        }

        $periodLabel = match ($type) {
            PeriodicBetType::Daily => sprintf('giorno %s', $periodStart->format('d/m/Y')),
            PeriodicBetType::Weekly => sprintf(
                'settimana %s - %s',
                $periodStart->format('d/m/Y'),
                $periodEnd->subDay()->format('d/m/Y'),
            ),
        };

        return [
            'message' => sprintf(
                '%s ha piazzato la bet %s su: %s (%s).',
                $this->displayName($player),
                $type->label(),
                $this->periodicChoiceLabel($type, $choiceValue),
                $periodLabel,
            ),
            'status' => self::BET_RESULT_PLACED,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function periodBoundsForType(PeriodicBetType $type, CarbonImmutable $reference): array
    {
        return match ($type) {
            PeriodicBetType::Daily => [$reference->startOfDay(), $reference->startOfDay()->addDay()],
            PeriodicBetType::Weekly => [
                $reference->startOfWeek(CarbonInterface::MONDAY)->startOfDay(),
                $reference->startOfWeek(CarbonInterface::MONDAY)->startOfDay()->addWeek(),
            ],
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function periodBoundsFromStart(PeriodicBetType $type, CarbonImmutable $periodStart): array
    {
        return match ($type) {
            PeriodicBetType::Daily => [$periodStart, $periodStart->addDay()],
            PeriodicBetType::Weekly => [$periodStart, $periodStart->addWeek()],
        };
    }

    protected function betCutoffForType(PeriodicBetType $type, CarbonImmutable $periodStart): CarbonImmutable
    {
        return match ($type) {
            PeriodicBetType::Daily => $periodStart->setTime(9, 30),
            PeriodicBetType::Weekly => $periodStart->setTime(12, 0),
        };
    }

    protected function periodicChoiceLabel(PeriodicBetType $type, string $choiceValue): string
    {
        return match ($type) {
            PeriodicBetType::Daily => DailyBetChoice::tryFrom($choiceValue)?->label() ?? $choiceValue,
            PeriodicBetType::Weekly => WeeklyBetChoice::tryFrom($choiceValue)?->label() ?? $choiceValue,
        };
    }

    protected function periodicBetWins(PeriodicBet $bet, float $totalMinutes): bool
    {
        return match ($bet->type) {
            PeriodicBetType::Daily => DailyBetChoice::tryFrom($bet->choice)?->isWinning($totalMinutes) ?? false,
            PeriodicBetType::Weekly => WeeklyBetChoice::tryFrom($bet->choice)?->isWinning($totalMinutes) ?? false,
        };
    }

    protected function totalMinutesBetween(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): float
    {
        $periodStartUtc = $periodStart->setTimezone('UTC');
        $periodEndUtc = $periodEnd->setTimezone('UTC');

        return (float) BathroomSession::query()
            ->whereNotNull('ended_at')
            ->where('ended_at', '>=', $periodStartUtc)
            ->where('ended_at', '<', $periodEndUtc)
            ->sum('duration_minutes');
    }

    protected function formatMinutes(float $minutes): string
    {
        $roundedMinutes = round($minutes, 2);
        $wholeMinutes = (int) round($roundedMinutes);
        $hours = intdiv($wholeMinutes, 60);
        $remainingMinutes = $wholeMinutes % 60;
        $humanReadable = $hours > 0
            ? sprintf('%dh %02dm', $hours, $remainingMinutes)
            : sprintf('%dm', $remainingMinutes);

        return sprintf('%.2f minuti (%s)', $roundedMinutes, $humanReadable);
    }

    protected function nowInBetTimezone(): CarbonImmutable
    {
        return CarbonImmutable::now($this->betTimezone());
    }

    protected function betTimezone(): string
    {
        return (string) config('services.telegram.bet_timezone', config('app.timezone', 'UTC'));
    }

    protected function pointsPerWinForPeriodicType(PeriodicBetType $type): int
    {
        return match ($type) {
            PeriodicBetType::Daily => (int) config(
                'services.telegram.points_per_win_daily',
                config('services.telegram.points_per_win', 10),
            ),
            PeriodicBetType::Weekly => (int) config(
                'services.telegram.points_per_win_weekly',
                config('services.telegram.points_per_win', 10),
            ),
        };
    }

    protected function chatStateForChat(string $chatId): TelegramChatState
    {
        return TelegramChatState::query()->firstOrCreate([
            'telegram_chat_id' => $chatId,
        ]);
    }

    protected function allKnownPlayersPlacedRound(BetRound $round): bool
    {
        $knownPlayerIds = $this->knownPlayerIdsForChat($round->telegram_chat_id);

        if (count($knownPlayerIds) < 2) {
            return false;
        }

        $roundPlayerIds = Bet::query()
            ->where('bet_round_id', $round->id)
            ->distinct()
            ->pluck('telegram_player_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $roundPlayerLookup = array_flip($roundPlayerIds);

        foreach ($knownPlayerIds as $knownPlayerId) {
            if (! isset($roundPlayerLookup[$knownPlayerId])) {
                return false;
            }
        }

        return true;
    }

    protected function allKnownPlayersPlacedPeriodicBet(string $chatId, PeriodicBetType $type, string $periodStartDate): bool
    {
        $knownPlayerIds = $this->knownPlayerIdsForChat($chatId);

        if (count($knownPlayerIds) < 2) {
            return false;
        }

        $periodPlayerIds = PeriodicBet::query()
            ->where('telegram_chat_id', $chatId)
            ->where('type', $type)
            ->whereDate('period_start_date', $periodStartDate)
            ->distinct()
            ->pluck('telegram_player_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $periodPlayerLookup = array_flip($periodPlayerIds);

        foreach ($knownPlayerIds as $knownPlayerId) {
            if (! isset($periodPlayerLookup[$knownPlayerId])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<int>
     */
    protected function knownPlayerIdsForChat(string $chatId): array
    {
        $roundPlayerIds = Bet::query()
            ->join('bet_rounds', 'bet_rounds.id', '=', 'bets.bet_round_id')
            ->where('bet_rounds.telegram_chat_id', $chatId)
            ->distinct()
            ->pluck('bets.telegram_player_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $periodicPlayerIds = PeriodicBet::query()
            ->where('telegram_chat_id', $chatId)
            ->distinct()
            ->pluck('telegram_player_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($roundPlayerIds, $periodicPlayerIds)));
    }
}

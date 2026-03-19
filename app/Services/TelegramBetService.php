<?php

namespace App\Services;

use App\Enums\BetOutcome;
use App\Models\BathroomSession;
use App\Models\Bet;
use App\Models\BetRound;
use App\Models\TelegramPlayer;
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
            return 'C\'e gia una scommessa aperta. Usa /bet <opzione> oppure /stop per chiuderla.';
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
            'Punta con: /bet <opzione>',
            'Shortcut validi: under15, 15-30, 30-45, over45',
            'Tracciamento: /start [nome-opzionale] e /stop',
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
            return 'Nessuna scommessa aperta. Avvia con /newbet.';
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
                'message' => 'Questo round e gia chiuso. Aspetta il prossimo /newbet.',
                'status' => self::BET_RESULT_ERROR,
            ];
        }

        $player = $this->upsertPlayer($telegramUser);

        $bet = Bet::query()
            ->where('bet_round_id', $round->id)
            ->where('telegram_player_id', $player->id)
            ->first();

        if ($bet) {
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
            return 'Classifica vuota. Crea una scommessa con /newbet.';
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
            '/newbet - apre una nuova scommessa (se ti serve senza /start)',
            '/bet <under15|15-30|30-45|over45> - piazza la puntata (accetta anche "from 15 to 30" ecc.)',
            '/stop - chiude timer e risolve la scommessa',
            '/link <codice> - collega account Telegram a utente portale',
            '/leaderboard - mostra la classifica punti',
        ]);
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
}

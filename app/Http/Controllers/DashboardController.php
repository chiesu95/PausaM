<?php

namespace App\Http\Controllers;

use App\Models\BathroomSession;
use App\Models\Bet;
use App\Models\BetRound;
use App\Models\TelegramPlayer;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $leaderboard = TelegramPlayer::query()
            ->orderByDesc('points')
            ->orderByDesc('wins')
            ->orderByDesc('total_bets')
            ->limit(20)
            ->get()
            ->map(fn (TelegramPlayer $player) => [
                'id' => $player->id,
                'fullName' => $player->full_name,
                'username' => $player->username,
                'points' => $player->points,
                'wins' => $player->wins,
                'totalBets' => $player->total_bets,
            ]);

        $activeSession = BathroomSession::query()
            ->active()
            ->latest('id')
            ->first();

        $openRound = BetRound::query()
            ->open()
            ->withCount('bets')
            ->latest('id')
            ->first();

        $recentRounds = BetRound::query()
            ->where('status', BetRound::STATUS_RESOLVED)
            ->with(['bathroomSession', 'bets.player'])
            ->latest('resolved_at')
            ->limit(10)
            ->get()
            ->map(function (BetRound $round) {
                $winners = $round->bets
                    ->where('is_winner', true)
                    ->map(function ($bet) {
                        if ($bet->player->username) {
                            return '@'.$bet->player->username;
                        }

                        return $bet->player->full_name;
                    })
                    ->values();

                return [
                    'id' => $round->id,
                    'resultLabel' => $round->result?->label(),
                    'resolvedAt' => optional($round->resolved_at)->toIso8601String(),
                    'durationMinutes' => optional($round->bathroomSession)->duration_minutes,
                    'winners' => $winners,
                    'betsCount' => $round->bets->count(),
                ];
            });

        return Inertia::render('Dashboard', [
            'stats' => [
                'totalPlayers' => TelegramPlayer::query()->count(),
                'totalRounds' => BetRound::query()->where('status', BetRound::STATUS_RESOLVED)->count(),
                'totalSessions' => BathroomSession::query()->whereNotNull('ended_at')->count(),
                'totalBets' => Bet::query()->count(),
            ],
            'activeSession' => $activeSession ? [
                'personName' => $activeSession->person_name,
                'startedAt' => $activeSession->started_at->toIso8601String(),
            ] : null,
            'openRound' => $openRound ? [
                'id' => $openRound->id,
                'telegramChatId' => $openRound->telegram_chat_id,
                'betsCount' => $openRound->bets_count,
                'createdAt' => optional($openRound->created_at)->toIso8601String(),
            ] : null,
            'leaderboard' => $leaderboard,
            'recentRounds' => $recentRounds,
        ]);
    }
}

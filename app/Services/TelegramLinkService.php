<?php

namespace App\Services;

use App\Models\TelegramLinkCode;
use App\Models\TelegramPlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TelegramLinkService
{
    public function generateCodeForUser(User $user): TelegramLinkCode
    {
        $ttlMinutes = max((int) config('services.telegram.link_code_ttl_minutes', 15), 1);

        return DB::transaction(function () use ($user, $ttlMinutes) {
            TelegramLinkCode::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->delete();

            return TelegramLinkCode::query()->create([
                'user_id' => $user->id,
                'code' => $this->generateUniqueCode(),
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);
        });
    }

    /**
     * @param  array{id?: int|string, username?: string, first_name?: string, last_name?: string}  $telegramUser
     */
    public function linkFromTelegram(array $telegramUser, string $rawCode): string
    {
        if (! isset($telegramUser['id'])) {
            return 'Impossibile identificare l\'utente Telegram.';
        }

        $codeValue = strtoupper(trim($rawCode));

        if ($codeValue === '') {
            return 'Codice mancante. Usa: /link <codice>';
        }

        $code = TelegramLinkCode::query()
            ->with('user')
            ->where('code', $codeValue)
            ->first();

        if (! $code) {
            return 'Codice non valido.';
        }

        if ($code->used_at) {
            return 'Questo codice e gia stato usato.';
        }

        if ($code->expires_at->isPast()) {
            return 'Codice scaduto. Generane uno nuovo dal portale.';
        }

        return DB::transaction(function () use ($code, $telegramUser) {
            $code = TelegramLinkCode::query()
                ->with('user')
                ->lockForUpdate()
                ->find($code->id);

            if (! $code || $code->used_at || $code->expires_at->isPast()) {
                return 'Codice non piu valido. Rigenera dal portale.';
            }

            $player = $this->upsertPlayer($telegramUser);

            if ($player->user_id && $player->user_id !== $code->user_id) {
                return 'Questo account Telegram e gia collegato a un altro utente del portale.';
            }

            TelegramPlayer::query()
                ->where('user_id', $code->user_id)
                ->where('id', '!=', $player->id)
                ->update(['user_id' => null]);

            $player->user_id = $code->user_id;
            $player->save();

            $code->used_at = now();
            $code->used_by_telegram_player_id = $player->id;
            $code->save();

            TelegramLinkCode::query()
                ->where('user_id', $code->user_id)
                ->whereNull('used_at')
                ->where('id', '!=', $code->id)
                ->update(['used_at' => now()]);

            return sprintf(
                'Account Telegram collegato con successo al portale: %s.',
                $code->user->name,
            );
        });
    }

    protected function generateUniqueCode(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = strtoupper(Str::random(10));

            if (! TelegramLinkCode::query()->where('code', $candidate)->exists()) {
                return $candidate;
            }
        }

        return strtoupper(Str::uuid()->toString());
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
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    /**
     * @param  array<int, array<int, array{text: string, callback_data: string}>>|null  $inlineKeyboard
     */
    public function sendMessage(string $chatId, string $text, ?array $inlineKeyboard = null): ?int
    {
        $token = config('services.telegram.bot_token');

        if (! $token) {
            Log::warning('Telegram bot token is missing. Message not sent.', ['chat_id' => $chatId]);

            return null;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($inlineKeyboard !== null) {
            $payload['reply_markup'] = [
                'inline_keyboard' => $inlineKeyboard,
            ];
        }

        $response = Http::timeout(10)->post(
            sprintf('https://api.telegram.org/bot%s/sendMessage', $token),
            $payload,
        );

        if (! $response->successful()) {
            Log::warning('Telegram sendMessage failed.', [
                'chat_id' => $chatId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $messageId = $response->json('result.message_id');

        return is_numeric($messageId) ? (int) $messageId : null;
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): void
    {
        $token = config('services.telegram.bot_token');

        if (! $token) {
            return;
        }

        $payload = [
            'callback_query_id' => $callbackQueryId,
        ];

        if ($text) {
            $payload['text'] = $text;
        }

        if ($showAlert) {
            $payload['show_alert'] = true;
        }

        Http::timeout(10)->post(
            sprintf('https://api.telegram.org/bot%s/answerCallbackQuery', $token),
            $payload,
        );
    }

    public function pinMessage(string $chatId, int $messageId): bool
    {
        $token = config('services.telegram.bot_token');

        if (! $token) {
            return false;
        }

        $response = Http::timeout(10)->post(
            sprintf('https://api.telegram.org/bot%s/pinChatMessage', $token),
            [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'disable_notification' => true,
            ],
        );

        if (! $response->successful()) {
            Log::warning('Telegram pinChatMessage failed.', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    public function unpinMessage(string $chatId, int $messageId): bool
    {
        $token = config('services.telegram.bot_token');

        if (! $token) {
            return false;
        }

        $response = Http::timeout(10)->post(
            sprintf('https://api.telegram.org/bot%s/unpinChatMessage', $token),
            [
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ],
        );

        if (! $response->successful()) {
            Log::warning('Telegram unpinChatMessage failed.', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }
}

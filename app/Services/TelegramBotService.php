<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    /**
     * @param  array<int, array<int, array{text: string, callback_data: string}>>|null  $inlineKeyboard
     */
    public function sendMessage(string $chatId, string $text, ?array $inlineKeyboard = null): void
    {
        $token = config('services.telegram.bot_token');

        if (! $token) {
            Log::warning('Telegram bot token is missing. Message not sent.', ['chat_id' => $chatId]);

            return;
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
        }
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
}

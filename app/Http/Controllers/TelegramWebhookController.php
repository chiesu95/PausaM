<?php

namespace App\Http\Controllers;

use App\Services\TelegramBetService;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramBetService $betService, TelegramBotService $botService): JsonResponse
    {
        $configuredSecret = config('services.telegram.webhook_secret');
        $receivedSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if ($configuredSecret && $receivedSecret !== $configuredSecret) {
            return response()->json(['ok' => false], 403);
        }

        $callbackQuery = $request->input('callback_query');

        if (is_array($callbackQuery)) {
            return $this->handleCallbackQuery($callbackQuery, $betService, $botService);
        }

        $message = $request->input('message');

        if (! is_array($message)) {
            return response()->json(['ok' => true]);
        }

        $text = trim((string) ($message['text'] ?? ''));
        $chatId = (string) ($message['chat']['id'] ?? '');

        if ($text === '' || $chatId === '' || ! str_starts_with($text, '/')) {
            return response()->json(['ok' => true]);
        }

        [$command, $arguments] = $this->extractCommand($text);
        $inlineKeyboard = null;

        $reply = match ($command) {
            'newbet' => $betService->openRound($chatId, isset($message['from']['id']) ? (string) $message['from']['id'] : null),
            'bet' => $arguments === ''
                ? 'Specifica una puntata: /bet <under15|15-30|30-45|over45>.'
                : $betService->placeBet($chatId, is_array($message['from'] ?? null) ? $message['from'] : [], $arguments),
            'start', 'startbath' => $betService->startBathroomSession($arguments !== '' ? $arguments : null),
            'stop', 'endbath' => $betService->endBathroomSessionAndResolve($chatId),
            'leaderboard' => $betService->leaderboard(),
            'help' => $betService->help(),
            default => 'Comando non riconosciuto. Usa /help.',
        };

        if ($command === 'newbet') {
            $round = $betService->openRoundForChat($chatId);

            if ($round) {
                $inlineKeyboard = $betService->roundInlineKeyboard($round->id);
            }
        }

        $botService->sendMessage($chatId, $reply, $inlineKeyboard);

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $callbackQuery
     */
    protected function handleCallbackQuery(array $callbackQuery, TelegramBetService $betService, TelegramBotService $botService): JsonResponse
    {
        $callbackQueryId = (string) ($callbackQuery['id'] ?? '');
        $chatId = (string) ($callbackQuery['message']['chat']['id'] ?? '');
        $data = (string) ($callbackQuery['data'] ?? '');

        if ($callbackQueryId === '' || $chatId === '') {
            return response()->json(['ok' => true]);
        }

        if (! preg_match('/^bet:(\d+):([a-z0-9_><-]+)$/i', $data, $matches)) {
            $botService->answerCallbackQuery($callbackQueryId, 'Selezione non valida.');

            return response()->json(['ok' => true]);
        }

        $reply = $betService->placeBetForRound(
            $chatId,
            is_array($callbackQuery['from'] ?? null) ? $callbackQuery['from'] : [],
            (int) $matches[1],
            strtolower($matches[2]),
        );

        $botService->answerCallbackQuery($callbackQueryId, mb_substr($reply, 0, 180));
        $botService->sendMessage($chatId, $reply);

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function extractCommand(string $text): array
    {
        if (! preg_match('/^\/([a-z_]+)(?:@\w+)?(?:\s+(.+))?$/i', $text, $matches)) {
            return ['', ''];
        }

        return [
            strtolower($matches[1]),
            trim($matches[2] ?? ''),
        ];
    }
}

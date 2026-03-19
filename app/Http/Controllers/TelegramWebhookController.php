<?php

namespace App\Http\Controllers;

use App\Services\TelegramBetService;
use App\Services\TelegramBotService;
use App\Services\TelegramLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        TelegramBetService $betService,
        TelegramBotService $botService,
        TelegramLinkService $telegramLinkService
    ): JsonResponse {
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
        $fromUser = is_array($message['from'] ?? null) ? $message['from'] : [];
        $fromTelegramId = isset($fromUser['id']) ? (string) $fromUser['id'] : null;
        $reply = '';

        switch ($command) {
            case 'newbet':
                $reply = $betService->openRound($chatId, $fromTelegramId);
                $round = $betService->openRoundForChat($chatId);
                if ($round) {
                    $inlineKeyboard = $betService->roundInlineKeyboard($round->id);
                }
                break;

            case 'bet':
                $reply = $arguments === ''
                    ? 'Specifica una puntata: /bet <under15|15-30|30-45|over45>.'
                    : $betService->placeBet($chatId, $fromUser, $arguments);
                break;

            case 'start':
            case 'startbath':
                $startResult = $betService->startBathroomSession($arguments !== '' ? $arguments : null);
                $reply = $startResult['message'];

                if ($startResult['started']) {
                    $reply .= "\n\n".$betService->openRound($chatId, $fromTelegramId);
                    $round = $betService->openRoundForChat($chatId);
                    if ($round) {
                        $inlineKeyboard = $betService->roundInlineKeyboard($round->id);
                    }
                }
                break;

            case 'stop':
            case 'endbath':
                $stopResult = $betService->endBathroomSessionAndResolveResult($chatId);
                $reply = $stopResult['message'];

                if ($stopResult['status'] === TelegramBetService::STOP_RESULT_RESOLVED) {
                    $reply .= "\n\n".$betService->leaderboard();
                }
                break;

            case 'leaderboard':
                $reply = $betService->leaderboard();
                break;

            case 'link':
                $reply = $arguments === ''
                    ? 'Specifica il codice: /link <codice>'
                    : $telegramLinkService->linkFromTelegram($fromUser, $arguments);
                break;

            case 'help':
                $reply = $betService->help();
                break;

            default:
                $reply = 'Comando non riconosciuto. Usa /help.';
                break;
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

        $result = $betService->placeBetForRoundResult(
            $chatId,
            is_array($callbackQuery['from'] ?? null) ? $callbackQuery['from'] : [],
            (int) $matches[1],
            strtolower($matches[2]),
        );

        $reply = $result['message'];
        $showAlert = $result['status'] !== TelegramBetService::BET_RESULT_PLACED;

        $botService->answerCallbackQuery($callbackQueryId, mb_substr($reply, 0, 180), $showAlert);

        if ($result['status'] === TelegramBetService::BET_RESULT_PLACED) {
            $botService->sendMessage($chatId, $reply);
        }

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

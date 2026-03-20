<?php

use App\Services\TelegramBetService;
use App\Services\TelegramBotService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('telegram:bets:open-daily', function () {
    $betService = app(TelegramBetService::class);
    $botService = app(TelegramBotService::class);
    $chatIds = $betService->scheduledChatIds();

    if ($chatIds === []) {
        $this->info('Nessuna chat trovata per apertura dailybet.');

        return;
    }

    foreach ($chatIds as $chatId) {
        $betService->resolveExpiredPeriodicBetsForChat($chatId);

        $messageId = $botService->sendMessage(
            $chatId,
            $betService->dailyBetPromptMessage(),
            $betService->dailyBetInlineKeyboard(),
        );

        if ($messageId === null) {
            $this->warn("Invio dailybet fallito per chat {$chatId}.");

            continue;
        }

        $betService->pinDailyMessageForCurrentDate($chatId, $messageId, $botService);
        $this->info("Dailybet aperta e pinnata per chat {$chatId}.");
    }
})->purpose('Apre la dailybet alle 08:30 e pinna il messaggio');

Artisan::command('telegram:bets:open-weekly', function () {
    $betService = app(TelegramBetService::class);
    $botService = app(TelegramBotService::class);
    $chatIds = $betService->scheduledChatIds();

    if ($chatIds === []) {
        $this->info('Nessuna chat trovata per apertura weeklybet.');

        return;
    }

    foreach ($chatIds as $chatId) {
        $betService->resolveExpiredPeriodicBetsForChat($chatId);

        $messageId = $botService->sendMessage(
            $chatId,
            $betService->weeklyBetPromptMessage(),
            $betService->weeklyBetInlineKeyboard(),
        );

        if ($messageId === null) {
            $this->warn("Invio weeklybet fallito per chat {$chatId}.");

            continue;
        }

        $betService->pinWeeklyMessageForCurrentWeek($chatId, $messageId, $botService);
        $this->info("Weeklybet aperta e pinnata per chat {$chatId}.");
    }
})->purpose('Apre la weeklybet il lunedi alle 08:30 e pinna il messaggio');

Artisan::command('telegram:bets:unpin-daily', function () {
    $betService = app(TelegramBetService::class);
    $botService = app(TelegramBotService::class);
    $chatIds = $betService->scheduledChatIds();

    if ($chatIds === []) {
        $this->info('Nessuna chat trovata per unpin dailybet.');

        return;
    }

    foreach ($chatIds as $chatId) {
        $betService->unpinDailyMessageIfExpired($chatId, $botService);
        $this->info("Unpin dailybet elaborato per chat {$chatId}.");
    }
})->purpose('Rimuove il pin della dailybet alle 09:30');

Artisan::command('telegram:bets:unpin-weekly', function () {
    $betService = app(TelegramBetService::class);
    $botService = app(TelegramBotService::class);
    $chatIds = $betService->scheduledChatIds();

    if ($chatIds === []) {
        $this->info('Nessuna chat trovata per unpin weeklybet.');

        return;
    }

    foreach ($chatIds as $chatId) {
        $betService->unpinWeeklyMessageIfExpired($chatId, $botService);
        $this->info("Unpin weeklybet elaborato per chat {$chatId}.");
    }
})->purpose('Rimuove il pin della weeklybet il lunedi alle 12:00');

$timezone = (string) config('services.telegram.bet_timezone', config('app.timezone', 'UTC'));

Schedule::command('telegram:bets:open-daily')
    ->weekdays()
    ->at('08:30')
    ->timezone($timezone);

Schedule::command('telegram:bets:open-weekly')
    ->mondays()
    ->at('08:30')
    ->timezone($timezone);

Schedule::command('telegram:bets:unpin-daily')
    ->dailyAt('09:30')
    ->timezone($timezone);

Schedule::command('telegram:bets:unpin-weekly')
    ->mondays()
    ->at('12:00')
    ->timezone($timezone);

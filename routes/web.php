<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->middleware('auth')->name('home');

Route::get('dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::post('telegram/webhook', TelegramWebhookController::class)
    ->name('telegram.webhook');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

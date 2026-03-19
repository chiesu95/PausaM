<?php

namespace App\Http\Controllers;

use App\Services\TelegramLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TelegramLinkCodeController extends Controller
{
    public function store(Request $request, TelegramLinkService $telegramLinkService): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        $linkCode = $telegramLinkService->generateCodeForUser($user);

        return back()->with([
            'status' => 'Codice Telegram generato.',
            'telegram_link_code' => $linkCode->code,
            'telegram_link_expires_at' => $linkCode->expires_at->toIso8601String(),
        ]);
    }
}

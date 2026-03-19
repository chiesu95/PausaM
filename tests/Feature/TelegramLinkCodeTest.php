<?php

use App\Models\TelegramLinkCode;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

test('authenticated user can generate a telegram link code from portal', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->post(route('telegram.link-code.store', absolute: false));

    $response->assertRedirect();
    $response->assertSessionHas('telegram_link_code');
    $response->assertSessionHas('telegram_link_expires_at');

    $linkCode = TelegramLinkCode::query()->where('user_id', $user->id)->first();

    expect($linkCode)->not()->toBeNull();
    expect($linkCode->used_at)->toBeNull();
});

test('generating a new link code invalidates previous pending codes', function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $user = User::factory()->create();
    $this->actingAs($user);

    TelegramLinkCode::query()->create([
        'user_id' => $user->id,
        'code' => 'OLDCODE1234',
        'expires_at' => now()->addMinutes(10),
    ]);

    $this->post(route('telegram.link-code.store', absolute: false));

    $codes = TelegramLinkCode::query()->where('user_id', $user->id)->get();

    expect($codes)->toHaveCount(1);
});

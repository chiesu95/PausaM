<?php

use App\Models\User;

test('guests are redirected from the registration screen', function () {
    $response = $this->get('/register');

    $response->assertRedirect('/login');
});

test('authenticated users can render the registration screen', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/register');

    $response->assertStatus(200);
});

test('authenticated users can create new users', function () {
    $creator = User::factory()->create();

    $response = $this->actingAs($creator)->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $this->assertAuthenticatedAs($creator);
    $response->assertRedirect('/register');
    $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
});

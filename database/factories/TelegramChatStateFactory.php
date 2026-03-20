<?php

namespace Database\Factories;

use App\Models\TelegramChatState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramChatState>
 */
class TelegramChatStateFactory extends Factory
{
    protected $model = TelegramChatState::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'telegram_chat_id' => (string) $this->faker->unique()->numberBetween(-999999999, -1000),
        ];
    }
}

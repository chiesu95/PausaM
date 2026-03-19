<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bet_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_player_id')->constrained()->cascadeOnDelete();
            $table->string('choice');
            $table->boolean('is_winner')->nullable();
            $table->unsignedInteger('awarded_points')->default(0);
            $table->timestamps();

            $table->unique(['bet_round_id', 'telegram_player_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bets');
    }
};

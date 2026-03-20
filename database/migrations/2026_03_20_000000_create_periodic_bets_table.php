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
        Schema::create('periodic_bets', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_chat_id');
            $table->foreignId('telegram_player_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->date('period_start_date');
            $table->string('choice');
            $table->timestamp('resolved_at')->nullable();
            $table->decimal('resolved_total_minutes', 8, 2)->nullable();
            $table->boolean('is_winner')->nullable();
            $table->timestamps();

            $table->unique(['telegram_chat_id', 'telegram_player_id', 'type', 'period_start_date'], 'periodic_bets_unique');
            $table->index(['telegram_chat_id', 'type', 'period_start_date'], 'periodic_bets_lookup_idx');
            $table->index(['resolved_at'], 'periodic_bets_resolved_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('periodic_bets');
    }
};

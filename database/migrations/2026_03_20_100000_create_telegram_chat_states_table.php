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
        Schema::create('telegram_chat_states', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_chat_id')->unique();
            $table->unsignedBigInteger('pinned_round_message_id')->nullable();
            $table->unsignedBigInteger('pinned_daily_message_id')->nullable();
            $table->date('pinned_daily_for_date')->nullable();
            $table->unsignedBigInteger('pinned_weekly_message_id')->nullable();
            $table->date('pinned_weekly_for_week_start_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_chat_states');
    }
};

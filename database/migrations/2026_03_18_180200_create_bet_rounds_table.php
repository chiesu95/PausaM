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
        Schema::create('bet_rounds', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_chat_id');
            $table->foreignId('bathroom_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('open');
            $table->string('result')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('opened_by_telegram_id')->nullable();
            $table->timestamps();

            $table->index(['telegram_chat_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bet_rounds');
    }
};

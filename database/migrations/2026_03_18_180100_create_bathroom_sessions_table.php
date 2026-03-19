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
        Schema::create('bathroom_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('person_name')->default('Persona');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->decimal('duration_minutes', 6, 2)->nullable();
            $table->string('outcome')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bathroom_sessions');
    }
};

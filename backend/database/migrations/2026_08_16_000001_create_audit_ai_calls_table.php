<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_ai_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('audit_request_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 32);
            $table->string('model', 64);
            $table->string('outcome', 16);
            // Nullable because a call that never returned still happened, and
            // may still have been billed — a row with unknown tokens is the
            // honest record of that, and is not the same thing as no row.
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['audit_request_id', 'created_at']);
            // Spend-by-day reporting reads this without touching the request.
            $table->index(['created_at', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_ai_calls');
    }
};

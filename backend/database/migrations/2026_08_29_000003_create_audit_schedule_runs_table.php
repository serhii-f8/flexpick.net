<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_schedule_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_schedule_id')->constrained()->cascadeOnDelete();
            $table->date('scheduled_for');
            $table->string('status', 20);
            $table->string('reason', 20)->nullable();
            $table->foreignId('audit_request_id')->nullable()->constrained('audit_requests')->nullOnDelete();
            $table->string('commit_sha', 64)->nullable();
            $table->timestamps();

            $table->index(['audit_schedule_id', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_schedule_runs');
    }
};

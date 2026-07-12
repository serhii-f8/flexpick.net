<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_funnel_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage', 40)->index();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_funnel_events');
    }
};

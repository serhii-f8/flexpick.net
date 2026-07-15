<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mailable');
            $table->string('recipient');
            $table->string('subject');
            $table->longText('body');
            $table->string('mailcoach_uuid')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_email_logs');
    }
};

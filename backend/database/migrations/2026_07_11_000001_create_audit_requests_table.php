<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('email')->index();
            $table->string('repo_url', 2048)->nullable();
            $table->text('message')->nullable();
            $table->string('status')->default('new')->index();
            $table->string('failure_reason', 1000)->nullable();
            $table->json('meta')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_requests');
    }
};

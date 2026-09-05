<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_approvals', function (Blueprint $table) {
            $table->id();
            // Unique: the database-level backstop against a double approval
            // slipping past the application's lockForUpdate guard (spec §7.2).
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('actor_type');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision');
            $table->text('note')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_approvals');
    }
};

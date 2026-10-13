<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_cardknox_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Cardknox's reusable xToken (from cc:save). Encrypted cast; never raw card data.
            $table->text('card_token');
            $table->string('card_brand')->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('card_exp', 4)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_cardknox_data');
    }
};

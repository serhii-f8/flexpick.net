<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cardknox_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            // One billing attempt = one invoice. The unique index is what makes a
            // double charge impossible under concurrent runs.
            $table->string('invoice_base', 40)->index();
            $table->string('invoice', 48)->unique();
            $table->string('ref_num')->nullable()->index();
            $table->string('purpose', 20);
            $table->dateTime('period_end')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cardknox_charges');
    }
};

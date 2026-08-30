<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_product_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('one_time_product_id')->constrained()->cascadeOnDelete();
            $table->integer('price');
            $table->json('quota_overrides')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'one_time_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_product_offerings');
    }
};

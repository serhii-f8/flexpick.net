<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner links are the referrer's personal ReferralCode now (spec §3.1);
 * this table never had a producer outside tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('partner_referral_links');
    }

    public function down(): void
    {
        Schema::create('partner_referral_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
};

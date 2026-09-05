<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('partner_tenant_id')->nullable()->after('tenant_id')->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('base_price_snapshot')->nullable()->after('partner_tenant_id');
            $table->json('quota_snapshot')->nullable()->after('base_price_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_tenant_id');
            $table->dropColumn(['base_price_snapshot', 'quota_snapshot']);
        });
    }
};

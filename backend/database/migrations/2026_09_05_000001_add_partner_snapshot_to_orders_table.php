<?php

use App\Constants\OrderType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('partner_tenant_id')->nullable()->after('tenant_id')->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('base_price_snapshot')->nullable()->after('partner_tenant_id');
            $table->json('quota_snapshot')->nullable()->after('base_price_snapshot');
            $table->foreignId('subscription_id')->nullable()->after('quota_snapshot')->constrained('subscriptions')->nullOnDelete();
            $table->string('type')->default(OrderType::PURCHASE->value)->after('subscription_id');
            $table->index(['partner_tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Drop both foreign keys before the composite index: the index on
            // (partner_tenant_id, status) is what backs the partner_tenant_id
            // FK on MySQL, so dropping it first (as the naive order would)
            // fails with "needed in a foreign key constraint".
            $table->dropForeign(['partner_tenant_id']);
            $table->dropForeign(['subscription_id']);
            $table->dropIndex(['partner_tenant_id', 'status']);
            $table->dropColumn(['partner_tenant_id', 'base_price_snapshot', 'quota_snapshot', 'subscription_id', 'type']);
        });
    }
};

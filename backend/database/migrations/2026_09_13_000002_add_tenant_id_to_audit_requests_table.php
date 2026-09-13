<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            // The monthly meter: forTenant + funding + tier + created_at.
            $table->index(['tenant_id', 'funding', 'tier', 'created_at'], 'audit_requests_tenant_meter_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->dropIndex('audit_requests_tenant_meter_index');
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};

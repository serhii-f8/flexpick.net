<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('partner_tenant_id')->nullable()->after('id')->constrained('tenants')->nullOnDelete();
            $table->timestamp('partner_attributed_at')->nullable()->after('partner_tenant_id');
            $table->string('partner_attribution_source')->nullable()->after('partner_attributed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_tenant_id');
            $table->dropColumn(['partner_attributed_at', 'partner_attribution_source']);
        });
    }
};

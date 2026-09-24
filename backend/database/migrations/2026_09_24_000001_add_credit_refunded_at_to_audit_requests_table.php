<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            // Set once when a run we could not analyze hands its credit back;
            // the quota meters skip rows carrying it.
            $table->timestamp('credit_refunded_at')->nullable()->after('funding');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->dropColumn('credit_refunded_at');
        });
    }
};

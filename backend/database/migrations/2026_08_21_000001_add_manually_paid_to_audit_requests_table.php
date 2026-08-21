<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual, out-of-band billing status for an audit -- client billing is
 * handled outside the system for now, so this is bookkeeping the super
 * admin sets directly, not something any payment provider or order flow
 * writes to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->boolean('manually_paid')->default(false)->after('prepaid');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->dropColumn('manually_paid');
        });
    }
};

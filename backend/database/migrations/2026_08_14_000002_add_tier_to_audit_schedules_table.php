<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_schedules', function (Blueprint $table) {
            // A literal, not AuditTier::AUTOMATED->value: that case was
            // removed with the Automated Health Report tier, and a migration
            // that reads a live enum stops replaying the moment the enum
            // moves on. The 2026_08_24 migration flips this default to
            // diagnostic, so a fresh install converges with an existing one.
            $table->string('tier')->default('automated')->after('frequency')->index();
        });
    }

    public function down(): void
    {
        Schema::table('audit_schedules', function (Blueprint $table) {
            $table->dropIndex(['tier']);
            $table->dropColumn('tier');
        });
    }
};

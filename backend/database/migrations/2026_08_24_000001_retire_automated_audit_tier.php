<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the `automated` audit tier (the Automated Health Report).
 *
 * Everything that tier did -- the five-scanner profile and its AI narration
 * budget -- is now what the base `diagnostic` tier does, so existing rows
 * move there: it is the tier whose report content actually matches what those
 * runs produced. Mapping them to `deep_ai` would have them claim a per-file
 * deep review that never ran.
 *
 * This is not optional cleanup. AuditRequest and AuditSchedule cast `tier` to
 * AuditTier, which no longer has an `automated` case, so any surviving row
 * throws a ValueError the first time it is read.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('audit_requests')->where('tier', 'automated')->update(['tier' => 'diagnostic']);
        DB::table('audit_schedules')->where('tier', 'automated')->update(['tier' => 'diagnostic']);

        Schema::table('audit_schedules', function (Blueprint $table): void {
            $table->string('tier')->default('diagnostic')->change();
        });
    }

    /**
     * Irreversible by design: the rows are indistinguishable from genuine
     * diagnostic runs once merged, so restoring the column default is the
     * only half of this that can honestly be undone.
     */
    public function down(): void
    {
        Schema::table('audit_schedules', function (Blueprint $table): void {
            $table->string('tier')->default('automated')->change();
        });
    }
};

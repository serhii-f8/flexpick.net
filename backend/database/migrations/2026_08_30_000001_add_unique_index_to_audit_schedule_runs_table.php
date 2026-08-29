<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One history row per (schedule, calendar day). The scheduler runs daily, and
 * a schedule that keeps being skipped never advances last_run_at, so without
 * this the table grew a row per day forever and the calendar showed a month of
 * duplicate dots for a single occurrence.
 *
 * The plain composite index is replaced rather than kept beside the unique
 * one: it is redundant (same leading columns) and, in MySQL, it is also the
 * index backing the audit_schedule_id foreign key -- so the unique index has
 * to exist before it can be dropped, and be restored after it in down().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_schedule_runs', function (Blueprint $table) {
            $table->unique(['audit_schedule_id', 'scheduled_for']);
            $table->dropIndex(['audit_schedule_id', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_schedule_runs', function (Blueprint $table) {
            $table->index(['audit_schedule_id', 'scheduled_for']);
            $table->dropUnique(['audit_schedule_id', 'scheduled_for']);
        });
    }
};

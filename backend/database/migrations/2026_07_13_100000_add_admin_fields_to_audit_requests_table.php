<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->text('admin_context')->nullable()->after('failure_reason');
            $table->json('pipeline_log')->nullable()->after('admin_context');
            $table->timestamp('analysis_started_at')->nullable()->after('pipeline_log');
            $table->timestamp('analysis_completed_at')->nullable()->after('analysis_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->dropColumn(['admin_context', 'pipeline_log', 'analysis_started_at', 'analysis_completed_at']);
        });
    }
};

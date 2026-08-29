<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_schedules', function (Blueprint $table) {
            $table->string('branch')->nullable()->after('repo_url');
            $table->unsignedTinyInteger('day_of_week')->nullable()->after('frequency');
            $table->string('last_commit_sha', 64)->nullable()->after('last_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('audit_schedules', function (Blueprint $table) {
            $table->dropColumn(['branch', 'day_of_week', 'last_commit_sha']);
        });
    }
};

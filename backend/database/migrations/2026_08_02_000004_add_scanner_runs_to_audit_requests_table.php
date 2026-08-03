<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table): void {
            $table->json('scanner_runs')->nullable()->after('pipeline_log');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table): void {
            $table->dropColumn('scanner_runs');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table): void {
            $table->unsignedInteger('ai_input_tokens')->nullable()->after('scanner_runs');
            $table->unsignedInteger('ai_output_tokens')->nullable()->after('ai_input_tokens');
            $table->unsignedInteger('scanner_ms')->nullable()->after('ai_output_tokens');
            $table->unsignedInteger('repo_size_kb')->nullable()->after('scanner_ms');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table): void {
            $table->dropColumn(['ai_input_tokens', 'ai_output_tokens', 'scanner_ms', 'repo_size_kb']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table): void {
            // The full selection log: per-file rank, raw and normalized signal
            // values, and the selection_version that produced them. Persisting
            // the contributions rather than just the paths is what makes the
            // weights tunable from real runs later.
            $table->json('risk_files')->nullable()->after('scanner_runs');
            // Deliberately separate from ai_* so the MARGINAL cost of tier 2
            // stays measurable (F5.12.6).
            $table->unsignedInteger('deep_review_input_tokens')->nullable()->after('ai_output_tokens');
            $table->unsignedInteger('deep_review_output_tokens')->nullable()->after('deep_review_input_tokens');
            $table->unsignedInteger('deep_review_ms')->nullable()->after('deep_review_output_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'risk_files',
                'deep_review_input_tokens',
                'deep_review_output_tokens',
                'deep_review_ms',
            ]);
        });
    }
};

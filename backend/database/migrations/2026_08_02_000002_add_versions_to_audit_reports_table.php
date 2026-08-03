<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_reports', function (Blueprint $table): void {
            $table->unsignedSmallInteger('scoring_version')->default(1)->after('payload')->index();
            $table->unsignedSmallInteger('payload_schema_version')->default(1)->after('scoring_version');
        });
    }

    public function down(): void
    {
        Schema::table('audit_reports', function (Blueprint $table): void {
            $table->dropColumn(['scoring_version', 'payload_schema_version']);
        });
    }
};

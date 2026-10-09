<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_reports', function (Blueprint $table) {
            // The developer report's PDF. pdf_path now holds the business
            // report's. Null on reports unlocked before the split -- the
            // download generates it on first request.
            $table->string('technical_pdf_path')->nullable()->after('pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('audit_reports', function (Blueprint $table) {
            $table->dropColumn('technical_pdf_path');
        });
    }
};

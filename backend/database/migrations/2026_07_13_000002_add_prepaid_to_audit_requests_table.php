<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->boolean('prepaid')->default(false)->after('free_run');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->dropColumn('prepaid');
        });
    }
};

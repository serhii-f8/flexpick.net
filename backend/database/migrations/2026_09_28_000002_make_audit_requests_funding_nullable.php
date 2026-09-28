<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            // Null funding marks an operator-provisioned run: an admin
            // created or comp'd it and decided its cost themselves, so the
            // multi-run sizer never charges it for extras.
            $table->string('funding')->default('allowance')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('audit_requests')->whereNull('funding')->update(['funding' => 'allowance']);

        Schema::table('audit_requests', function (Blueprint $table) {
            $table->string('funding')->default('allowance')->change();
        });
    }
};

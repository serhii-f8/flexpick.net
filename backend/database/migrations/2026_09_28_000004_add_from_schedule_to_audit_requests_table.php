<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            // Created unattended by app:run-scheduled-audits. Nobody is there
            // to agree to a charge, so sizing may cover such a request's extra
            // runs from the metered pool only, never from purchased credits.
            $table->boolean('from_schedule')->default(false)->after('extra_purchased_runs');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->dropColumn('from_schedule');
        });
    }
};

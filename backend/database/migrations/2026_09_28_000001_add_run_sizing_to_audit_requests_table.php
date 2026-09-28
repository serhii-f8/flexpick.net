<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            // Total runs this audit costs, settled once from the repo's size
            // after the clone. Null until sized; a retry re-uses it so a
            // request is never charged twice.
            $table->unsignedSmallInteger('run_count')->nullable()->after('credit_refunded_at');
            // Runs beyond the first, split by the pool they came from so the
            // meters and refund() can each account for them.
            $table->unsignedSmallInteger('extra_metered_runs')->default(0)->after('run_count');
            $table->unsignedSmallInteger('extra_purchased_runs')->default(0)->after('extra_metered_runs');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->dropColumn(['run_count', 'extra_metered_runs', 'extra_purchased_runs']);
        });
    }
};

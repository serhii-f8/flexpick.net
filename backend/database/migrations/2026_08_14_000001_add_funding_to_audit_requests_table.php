<?php

use App\Constants\AuditFunding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->string('funding')->default(AuditFunding::ALLOWANCE->value)->after('free_run')->index();
        });

        // Precedence: a paid run first, then a free-quota run, then anything
        // dashboard-sourced (which came out of the plan), then free. Tier is
        // deliberately NOT backfilled -- historical dashboard runs really did
        // execute the diagnostic profile.
        DB::table('audit_requests')->where('prepaid', true)
            ->update(['funding' => AuditFunding::PURCHASE->value]);

        DB::table('audit_requests')->where('prepaid', false)->where('free_run', true)
            ->update(['funding' => AuditFunding::FREE->value]);

        DB::table('audit_requests')->where('prepaid', false)->where('free_run', false)
            ->where('source', '!=', 'dashboard')
            ->update(['funding' => AuditFunding::FREE->value]);
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->dropIndex(['funding']);
            $table->dropColumn('funding');
        });
    }
};

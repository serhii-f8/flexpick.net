<?php

use App\Constants\AuditTier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_schedules', function (Blueprint $table) {
            // Automated, not diagnostic: a schedule is a subscriber feature,
            // and the allowance the command already checks meters that tier.
            $table->string('tier')->default(AuditTier::AUTOMATED->value)->after('frequency')->index();
        });
    }

    public function down(): void
    {
        Schema::table('audit_schedules', function (Blueprint $table) {
            $table->dropIndex(['tier']);
            $table->dropColumn('tier');
        });
    }
};

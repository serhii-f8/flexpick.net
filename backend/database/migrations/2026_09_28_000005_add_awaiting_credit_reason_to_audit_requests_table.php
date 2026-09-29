<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            // Why an awaiting_credit close happened (AwaitingCreditReason):
            // the dashboard hint must not tell a too-large repo to buy credit.
            // Null on rows closed before this column existed.
            $table->string('awaiting_credit_reason')->nullable()->after('failure_reason');
            // A not_analyzable close whose workspace had a connection for the
            // repo's provider (live, or just deleted by an invalid_grant
            // refresh): the customer reconnects rather than connects.
            $table->boolean('git_reconnect_required')->default(false)->after('awaiting_credit_reason');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->dropColumn(['awaiting_credit_reason', 'git_reconnect_required']);
        });
    }
};

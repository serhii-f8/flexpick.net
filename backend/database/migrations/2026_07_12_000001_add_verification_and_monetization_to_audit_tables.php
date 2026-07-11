<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->timestamp('email_verified_at')->nullable()->after('status');
            $table->boolean('marketing_consent')->default(false)->after('email_verified_at');
            $table->timestamp('consented_at')->nullable()->after('marketing_consent');
            $table->boolean('free_run')->default(false)->index()->after('consented_at');
            $table->string('source')->default('web')->after('free_run');
            $table->foreignId('user_id')->nullable()->after('source')->constrained()->nullOnDelete();
        });

        Schema::table('audit_reports', function (Blueprint $table) {
            $table->timestamp('unlocked_at')->nullable()->after('pdf_path');
            $table->foreignId('unlock_order_id')->nullable()->after('unlocked_at')->constrained('orders')->nullOnDelete();
            $table->string('pdf_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['email_verified_at', 'marketing_consent', 'consented_at', 'free_run', 'source']);
        });

        Schema::table('audit_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unlock_order_id');
            $table->dropColumn(['unlocked_at']);
            $table->string('pdf_path')->nullable(false)->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_ai_calls', function (Blueprint $table): void {
            // Where the row came from. A call the pipeline watched return is
            // evidence; a call transcribed from the provider's console after
            // the fact is testimony. Both belong in a spend ledger, but anyone
            // auditing a figure needs to be able to tell them apart — and
            // without this column a reconstruction is indistinguishable from a
            // measurement forever after.
            $table->string('source', 16)->default('pipeline')->after('outcome');
        });
    }

    public function down(): void
    {
        Schema::table('audit_ai_calls', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};

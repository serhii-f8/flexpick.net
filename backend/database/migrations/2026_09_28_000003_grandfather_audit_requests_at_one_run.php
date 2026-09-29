<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Every request that exists now was sold as a single run, before run
        // sizing shipped. A NULL run_count would let a retry or a queued row
        // be sized and charged extras under rules the customer never agreed
        // to. Rows created after this stay NULL until they are sized.
        DB::table('audit_requests')->whereNull('run_count')->update(['run_count' => 1]);
    }

    public function down(): void
    {
        // Intentionally a no-op: once backfilled, a grandfathered row cannot be
        // told apart from one that was genuinely sized at a single run.
    }
};

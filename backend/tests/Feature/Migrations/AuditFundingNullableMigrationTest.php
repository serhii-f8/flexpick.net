<?php

namespace Tests\Feature\Migrations;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTest;

class AuditFundingNullableMigrationTest extends FeatureTest
{
    private const MIGRATION = 'migrations/2026_09_28_000002_make_audit_requests_funding_nullable.php';

    public function test_up_allows_null_funding(): void
    {
        $migration = require database_path(self::MIGRATION);
        $migration->up();

        $email = 'funding-nullable-up-'.uniqid().'@example.com';

        DB::beginTransaction();

        try {
            DB::table('audit_requests')->insert($this->row($email) + ['funding' => null]);

            $this->assertNull(
                DB::table('audit_requests')->where('email', $email)->value('funding'),
                'up() must leave the funding column nullable.'
            );
        } finally {
            DB::rollBack();
        }
    }

    public function test_down_backfills_null_rows_and_restores_the_not_null_column(): void
    {
        // MySQL commits implicitly on DDL, so the migration's down() must run
        // outside a transaction; the test cleans up its own rows instead.
        $prefix = 'funding-nullable-down-'.uniqid().'-';
        $nullEmail = $prefix.'null@example.com';
        $freeEmail = $prefix.'free@example.com';

        DB::table('audit_requests')->insert($this->row($nullEmail) + ['funding' => null]);
        DB::table('audit_requests')->insert($this->row($freeEmail) + ['funding' => 'free']);

        $migration = require database_path(self::MIGRATION);

        try {
            $migration->down();

            $this->assertSame(
                'allowance',
                DB::table('audit_requests')->where('email', $nullEmail)->value('funding'),
                'down() must backfill null rows to the prior default.'
            );
            $this->assertSame(
                'free',
                DB::table('audit_requests')->where('email', $freeEmail)->value('funding'),
                'down() must leave non-null rows untouched.'
            );

            try {
                DB::table('audit_requests')->insert($this->row($prefix.'after@example.com') + ['funding' => null]);
                $this->fail('down() must restore the NOT NULL constraint on funding.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('funding', $e->getMessage());
            }
        } finally {
            // Restore the nullable state the rest of the suite depends on.
            $migration->up();
            DB::table('audit_requests')->where('email', 'like', $prefix.'%')->delete();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $email): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => 'Funding nullable test row',
            'email' => $email,
            'status' => 'new',
            'prepaid' => false,
            'free_run' => false,
            'source' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}

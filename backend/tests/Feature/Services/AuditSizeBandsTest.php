<?php

namespace Tests\Feature\Services;

use App\Models\Config;
use App\Services\AuditReport\AuditSizeBands;
use App\Services\ConfigService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTest;

class AuditSizeBandsTest extends FeatureTest
{
    protected function tearDown(): void
    {
        // No per-test DB reset in this suite; a stored override would leak
        // into every pipeline test that runs afterwards.
        Config::where('key', 'audit.size_bands')->delete();

        parent::tearDown();
    }

    public function test_defaults_apply_when_nothing_is_stored(): void
    {
        $bands = app(AuditSizeBands::class);

        $this->assertSame(AuditSizeBands::DEFAULT_BANDS, $bands->bands());
        $this->assertSame(1, $bands->runsFor(100000));
        $this->assertSame(2, $bands->runsFor(100001));
        $this->assertNull($bands->runsFor(300001));
        $this->assertSame(300000, $bands->ceiling());
    }

    public function test_a_stored_override_wins_and_is_sorted(): void
    {
        app(ConfigService::class)->set('audit.size_bands', json_encode([
            ['max_loc' => 90000, 'runs' => 3],
            ['max_loc' => 40000, 'runs' => 1],
        ]));

        $bands = app(AuditSizeBands::class);

        $this->assertSame([['max_loc' => 40000, 'runs' => 1], ['max_loc' => 90000, 'runs' => 3]], $bands->bands());
        $this->assertSame(3, $bands->runsFor(40001));
        $this->assertNull($bands->runsFor(90001));
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidBands(): array
    {
        return [
            'malformed json' => ['{nope'],
            'empty list' => ['[]'],
            'zero runs' => [json_encode([['max_loc' => 1000, 'runs' => 0]])],
            'duplicate max_loc' => [json_encode([['max_loc' => 1000, 'runs' => 1], ['max_loc' => 1000, 'runs' => 2]])],
            'runs shrink as size grows' => [json_encode([['max_loc' => 1000, 'runs' => 2], ['max_loc' => 5000, 'runs' => 1]])],
            'missing key' => [json_encode([['max_loc' => 1000]])],
        ];
    }

    #[DataProvider('invalidBands')]
    public function test_an_invalid_override_falls_back_to_the_defaults(mixed $stored): void
    {
        app(ConfigService::class)->set('audit.size_bands', $stored);

        $this->assertSame(AuditSizeBands::DEFAULT_BANDS, app(AuditSizeBands::class)->bands());
    }

    public function test_describe_generates_copy_from_the_same_bands(): void
    {
        app(ConfigService::class)->set('audit.size_bands', json_encode([
            ['max_loc' => 61234, 'runs' => 1],
            ['max_loc' => 187654, 'runs' => 3],
        ]));

        $this->assertSame([
            'Up to 61,234 lines of code: 1 run',
            '61,235–187,654 lines of code: 3 runs',
            'Over 187,654 lines of code: contact us for a scoped audit',
        ], app(AuditSizeBands::class)->describe());
    }
}

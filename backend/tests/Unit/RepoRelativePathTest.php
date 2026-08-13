<?php

namespace Tests\Unit;

use App\Services\AuditReport\Scanners\RepoRelativePath;
use PHPUnit\Framework\TestCase;

class RepoRelativePathTest extends TestCase
{
    private const ROOT = '/var/www/html/storage/app/audit-workdirs/0199a1f2';

    public function test_strips_the_repository_root_from_an_absolute_path(): void
    {
        // scc, Gitleaks and Semgrep are all invoked with the absolute clone
        // path and echo it back in their output.
        $this->assertSame(
            'src/Db/Query.php',
            RepoRelativePath::from(self::ROOT, self::ROOT.'/src/Db/Query.php'),
        );
    }

    public function test_tolerates_a_trailing_slash_on_the_root(): void
    {
        $this->assertSame(
            'src/Db/Query.php',
            RepoRelativePath::from(self::ROOT.'/', self::ROOT.'/src/Db/Query.php'),
        );
    }

    public function test_leaves_an_already_relative_path_alone(): void
    {
        // jscpd reports relative to the directory it was pointed at.
        $this->assertSame('src/DupA.php', RepoRelativePath::from(self::ROOT, 'src/DupA.php'));
    }

    public function test_strips_a_leading_current_directory_marker(): void
    {
        $this->assertSame('src/DupA.php', RepoRelativePath::from(self::ROOT, './src/DupA.php'));
    }

    public function test_decodes_a_file_uri(): void
    {
        $this->assertSame(
            'src/My App/Query.php',
            RepoRelativePath::from(self::ROOT, 'file://'.self::ROOT.'/src/My%20App/Query.php'),
        );
    }

    public function test_returns_empty_for_a_path_outside_the_repository(): void
    {
        // Never fall back to the absolute path: it would fail every is_file()
        // join downstream AND leak the workdir into customer-facing output.
        $this->assertSame('', RepoRelativePath::from(self::ROOT, '/etc/passwd'));
    }

    public function test_returns_empty_for_the_repository_root_itself(): void
    {
        $this->assertSame('', RepoRelativePath::from(self::ROOT, self::ROOT));
    }

    public function test_does_not_treat_a_sibling_directory_as_inside_the_repository(): void
    {
        // Prefix matching without the separator would turn the sibling clone
        // "…/0199a1f2-old/x.php" into the relative path "-old/x.php".
        $this->assertSame('', RepoRelativePath::from(self::ROOT, self::ROOT.'-old/x.php'));
    }
}

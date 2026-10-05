<?php

namespace App\Services\AuditReport\Paths;

enum PathClass: string
{
    case Source = 'source';
    case Test = 'test';
    case Docs = 'docs';
    case Example = 'example';
    case Generated = 'generated';
    case Vendored = 'vendored';
    case Lockfile = 'lockfile';

    /** Hand-written code the report measures: structure, duplication, hotspots, review. */
    public function isAnalyzed(): bool
    {
        return in_array($this, [self::Source, self::Test, self::Example], true);
    }
}

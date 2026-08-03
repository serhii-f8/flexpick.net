<?php

namespace App\Services\AuditReport\Scanners;

enum ScannerOutcome: string
{
    case OK = 'ok';
    case FAILED = 'failed';
    case TIMEOUT = 'timeout';
    case UNAVAILABLE = 'unavailable';
}

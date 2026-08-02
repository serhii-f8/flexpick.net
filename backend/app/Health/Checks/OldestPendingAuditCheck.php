<?php

namespace App\Health\Checks;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class OldestPendingAuditCheck extends Check
{
    public function run(): Result
    {
        return Result::make()->ok();
    }
}

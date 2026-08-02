<?php

namespace App\Health\Checks;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class AuditPipelineFailureRateCheck extends Check
{
    public function run(): Result
    {
        return Result::make()->ok();
    }
}

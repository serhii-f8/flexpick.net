<?php

namespace App\Health\Checks;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class MailFailureRateCheck extends Check
{
    public function run(): Result
    {
        return Result::make()->ok();
    }
}

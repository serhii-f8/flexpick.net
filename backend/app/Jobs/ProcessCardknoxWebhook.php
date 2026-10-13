<?php

namespace App\Jobs;

use App\Services\PaymentProviders\Cardknox\CardknoxChargeLedger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessCardknoxWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public array $fields) {}

    public function handle(CardknoxChargeLedger $ledger): void
    {
        $ledger->applyWebhook($this->fields);
    }
}

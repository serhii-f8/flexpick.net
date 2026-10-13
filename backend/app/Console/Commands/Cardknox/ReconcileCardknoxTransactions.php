<?php

namespace App\Console\Commands\Cardknox;

use App\Services\PaymentProviders\Cardknox\CardknoxChargeLedger;
use Illuminate\Console\Command;

class ReconcileCardknoxTransactions extends Command
{
    protected $signature = 'app:reconcile-cardknox-transactions {--charge= : Only this cardknox_charges id}';

    protected $description = 'Settle Cardknox charges still pending after their webhook should have arrived';

    public function handle(CardknoxChargeLedger $ledger): int
    {
        $chargeId = $this->option('charge');

        $settled = $ledger->reconcilePending(now()->subMinutes(15), $chargeId === null ? null : (int) $chargeId);

        $this->info("Settled {$settled} pending Cardknox charge(s).");

        return self::SUCCESS;
    }
}

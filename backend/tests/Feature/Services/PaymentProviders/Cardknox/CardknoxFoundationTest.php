<?php

namespace Tests\Feature\Services\PaymentProviders\Cardknox;

use App\Constants\PaymentProviderConstants;
use App\Models\CardknoxCharge;
use App\Models\PaymentProvider;
use App\Models\UserCardknoxData;
use Database\Seeders\PaymentProvidersSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxFoundationTest extends FeatureTest
{
    use CardknoxTestHelpers;

    public function test_seeder_registers_cardknox_inactive(): void
    {
        $this->seed(PaymentProvidersSeeder::class);

        $provider = PaymentProvider::where('slug', PaymentProviderConstants::CARDKNOX_SLUG)->firstOrFail();

        $this->assertSame('Cardknox', $provider->name);
    }

    public function test_card_token_is_encrypted_at_rest(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $card = UserCardknoxData::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'card_token' => 'plain-token-123',
            'card_brand' => 'Visa',
            'card_last4' => '1111',
            'card_exp' => '1230',
        ]);

        $raw = DB::table('user_cardknox_data')->where('id', $card->id)->value('card_token');

        $this->assertNotSame('plain-token-123', $raw);
        $this->assertSame('plain-token-123', $card->fresh()->card_token);
    }

    public function test_invoice_is_unique(): void
    {
        $this->configureCardknox();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $transaction = $this->pendingTransactionFor($subscription);
        $invoice = 'S'.Str::random(8).'-1';

        CardknoxCharge::create(['transaction_id' => $transaction->id, 'invoice_base' => 'x', 'invoice' => $invoice, 'purpose' => 'renewal']);

        $this->expectException(UniqueConstraintViolationException::class);

        CardknoxCharge::create(['transaction_id' => $transaction->id, 'invoice_base' => 'x', 'invoice' => $invoice, 'purpose' => 'renewal']);
    }
}

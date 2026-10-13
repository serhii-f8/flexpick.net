<?php

namespace App\Services\PaymentProviders\Cardknox;

use App\Client\CardknoxClient;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserCardknoxData;

/**
 * Turns iFields single-use tokens into a reusable Cardknox token. Tokenizing
 * and remembering are separate so a one-time purchase can pay with a card
 * without replacing the card a subscription renews on.
 */
class CardknoxCardVault
{
    public function __construct(private CardknoxClient $client) {}

    public function tokenize(string $cardSut, string $cvvSut, string $exp): CardknoxResult
    {
        $result = $this->client->save($cardSut, $cvvSut, $exp);

        if ($result->isApproved() && $result->token === null) {
            return CardknoxResult::error(__('Your card could not be saved.'));
        }

        return $result;
    }

    public function remember(User $user, ?Tenant $tenant, CardknoxResult $tokenized, string $exp): UserCardknoxData
    {
        return UserCardknoxData::query()->updateOrCreate(
            ['tenant_id' => $tenant?->id, 'user_id' => $user->id],
            [
                'card_token' => (string) $tokenized->token,
                'card_brand' => $tokenized->cardType,
                'card_last4' => $tokenized->maskedCard === null ? null : substr($tokenized->maskedCard, -4),
                'card_exp' => $tokenized->exp ?? $exp,
            ],
        );
    }

    public function cardFor(?int $tenantId, int $userId): ?UserCardknoxData
    {
        return UserCardknoxData::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();
    }
}

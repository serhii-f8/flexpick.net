<?php

namespace App\Services\PaymentProviders\Cardknox;

class CardknoxConfiguration
{
    public function transactionKey(): ?string
    {
        return $this->value('transaction_key');
    }

    public function ifieldsKey(): ?string
    {
        return $this->value('ifields_key');
    }

    public function webhookPin(): ?string
    {
        return $this->value('webhook_pin');
    }

    public function gatewayUrl(): string
    {
        return rtrim($this->value('gateway_url') ?? 'https://x1.cardknox.com', '/');
    }

    public function ifieldsVersion(): string
    {
        return $this->value('ifields_version') ?? '2.15.2401.3101';
    }

    /**
     * Cardknox cannot be switched on without all three secrets: a missing PIN
     * would leave the webhook endpoint unable to authenticate anything.
     */
    public function isComplete(): bool
    {
        return $this->transactionKey() !== null
            && $this->ifieldsKey() !== null
            && self::isValidPin($this->webhookPin());
    }

    /** Cardknox requires a letters-and-digits PIN of at least 15 characters. */
    public static function isValidPin(?string $pin): bool
    {
        return $pin !== null && preg_match('/^[A-Za-z0-9]{15,}$/', $pin) === 1;
    }

    private function value(string $key): ?string
    {
        $value = config('services.cardknox.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

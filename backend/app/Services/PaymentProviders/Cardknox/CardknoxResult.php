<?php

namespace App\Services\PaymentProviders\Cardknox;

final class CardknoxResult
{
    public function __construct(
        public readonly CardknoxOutcome $outcome,
        public readonly ?string $refNum = null,
        public readonly ?string $token = null,
        public readonly ?string $maskedCard = null,
        public readonly ?string $cardType = null,
        public readonly ?string $exp = null,
        public readonly ?string $error = null,
    ) {}

    /** Gateway responses and webhook bodies share the same x-field names. */
    public static function fromResponse(array $fields): self
    {
        $outcome = match ($fields['xResult'] ?? null) {
            'A' => CardknoxOutcome::Approved,
            'D' => CardknoxOutcome::Declined,
            'E' => CardknoxOutcome::Error,
            default => CardknoxOutcome::Unknown,
        };

        return new self(
            outcome: $outcome,
            refNum: self::stringOrNull($fields['xRefNum'] ?? null),
            token: self::stringOrNull($fields['xToken'] ?? null),
            maskedCard: self::stringOrNull($fields['xMaskedCardNumber'] ?? null),
            cardType: self::stringOrNull($fields['xCardType'] ?? null),
            exp: self::stringOrNull($fields['xExp'] ?? null),
            error: self::stringOrNull($fields['xError'] ?? null),
        );
    }

    public static function unknown(string $error): self
    {
        return new self(CardknoxOutcome::Unknown, error: $error);
    }

    public static function error(string $error): self
    {
        return new self(CardknoxOutcome::Error, error: $error);
    }

    /** Trials and fully-discounted charges succeed without a gateway call. */
    public static function approvedWithoutCharge(): self
    {
        return new self(CardknoxOutcome::Approved);
    }

    /** An attempt for this item is already pending or has succeeded. */
    public static function inFlight(): self
    {
        return new self(CardknoxOutcome::Unknown, error: __('A payment for this is already in progress.'));
    }

    public function isApproved(): bool
    {
        return $this->outcome === CardknoxOutcome::Approved;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}

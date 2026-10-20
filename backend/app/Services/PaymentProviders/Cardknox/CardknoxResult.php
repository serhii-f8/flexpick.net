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

    /**
     * Report rows and webhook bodies carry the outcome as a word in
     * xResponseResult ("Approved") rather than the gateway's one-letter
     * xResult; everything else shares the same x-field names.
     */
    public static function fromNotification(array $fields): self
    {
        $result = match (strtolower((string) ($fields['xResponseResult'] ?? ''))) {
            'approved' => 'A',
            'declined' => 'D',
            'error' => 'E',
            default => $fields['xResult'] ?? null,
        };

        return self::fromResponse(array_merge($fields, [
            'xResult' => $result,
            'xError' => $fields['xError'] ?? $fields['xResponseError'] ?? null,
        ]));
    }

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

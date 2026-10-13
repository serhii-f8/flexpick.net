<?php

namespace App\Client;

use App\Services\PaymentProviders\Cardknox\CardknoxConfiguration;
use App\Services\PaymentProviders\Cardknox\CardknoxResult;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over the Cardknox (Sola) Transaction API JSON endpoint.
 * No business logic: every call returns a CardknoxResult, and anything other
 * than a well-formed gateway answer is CardknoxOutcome::Unknown.
 *
 * Field names not covered by public docs (report rows, xRecurringIndicator
 * values) are confirmed in the sandbox pass — see Task 14.
 */
class CardknoxClient
{
    public const RECURRING = 'Recurring';

    private const VERSION = '5.0.0';

    public function __construct(private CardknoxConfiguration $configuration) {}

    public function save(string $cardSut, string $cvvSut, string $exp): CardknoxResult
    {
        return $this->send([
            'xCommand' => 'cc:Save',
            'xCardNum' => $cardSut,
            'xCVV' => $cvvSut,
            'xExp' => $exp,
        ]);
    }

    public function sale(string $token, int $amountCents, string $invoice, ?string $recurringIndicator = null): CardknoxResult
    {
        $fields = [
            'xCommand' => 'cc:Sale',
            'xToken' => $token,
            'xAmount' => self::formatAmount($amountCents),
            'xInvoice' => $invoice,
        ];

        if ($recurringIndicator !== null) {
            $fields['xRecurringIndicator'] = $recurringIndicator;
        }

        return $this->send($fields);
    }

    public function refund(string $refNum, ?int $amountCents = null): CardknoxResult
    {
        $fields = ['xCommand' => 'cc:Refund', 'xRefNum' => $refNum];

        if ($amountCents !== null) {
            $fields['xAmount'] = self::formatAmount($amountCents);
        }

        return $this->send($fields);
    }

    public function void(string $refNum): CardknoxResult
    {
        return $this->send(['xCommand' => 'cc:Void', 'xRefNum' => $refNum]);
    }

    /**
     * @return CardknoxResult|null null when the gateway has no sale with this
     *                             invoice; an Unknown result when the lookup itself failed
     */
    public function findByInvoice(string $invoice, Carbon $since): ?CardknoxResult
    {
        $body = $this->post([
            'xCommand' => 'report:all',
            'xBeginDate' => $since->format('Y-m-d H:i:s'),
            'xEndDate' => now()->format('Y-m-d H:i:s'),
            'xMaxRecords' => 1000,
        ]);

        if ($body === null) {
            return CardknoxResult::unknown('Report lookup failed');
        }

        foreach ((array) ($body['xReportData'] ?? []) as $row) {
            if (($row['xInvoice'] ?? null) !== $invoice || strtolower((string) ($row['xCommand'] ?? '')) !== 'cc:sale') {
                continue;
            }

            $result = match (strtolower((string) ($row['xResponseResult'] ?? ''))) {
                'approved' => 'A',
                'declined' => 'D',
                'error' => 'E',
                default => null,
            };

            return CardknoxResult::fromResponse(array_merge($row, ['xResult' => $result]));
        }

        return null;
    }

    public static function formatAmount(int $amountCents): string
    {
        return number_format($amountCents / 100, 2, '.', '');
    }

    private function send(array $fields): CardknoxResult
    {
        $body = $this->post($fields);

        return $body === null
            ? CardknoxResult::unknown('No usable gateway response')
            : CardknoxResult::fromResponse($body);
    }

    private function post(array $fields): ?array
    {
        $key = $this->configuration->transactionKey()
            ?? throw new RuntimeException('Cardknox is not configured: missing transaction key.');

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(45)
                ->post($this->configuration->gatewayUrl().'/gatewayjson', array_merge([
                    'xKey' => $key,
                    'xVersion' => self::VERSION,
                    'xSoftwareName' => (string) config('app.name'),
                    'xSoftwareVersion' => '1.0',
                ], $fields));
        } catch (ConnectionException) {
            return null;
        }

        $json = $response->json();

        return $response->successful() && is_array($json) ? $json : null;
    }
}

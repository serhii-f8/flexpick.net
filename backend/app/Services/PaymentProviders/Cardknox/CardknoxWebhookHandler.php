<?php

namespace App\Services\PaymentProviders\Cardknox;

use App\Jobs\ProcessCardknoxWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Cardknox posts one form-encoded notification per transaction, never
 * retries, and gives up after 10 seconds. Verify, queue, answer at once.
 */
class CardknoxWebhookHandler
{
    /** Only what processing needs reaches the queue; card tokens never do. */
    private const KEPT_FIELDS = ['xCommand', 'xResult', 'xResponseResult', 'xResponseError', 'xStatus', 'xError', 'xErrorCode', 'xRefNum', 'xInvoice', 'xAmount'];

    public function __construct(private CardknoxConfiguration $configuration) {}

    public function handleWebhook(Request $request): JsonResponse
    {
        $pin = $this->configuration->webhookPin();
        parse_str($request->getContent(), $fields);
        $header = (string) $request->header('ck-signature', '');

        if (! CardknoxConfiguration::isValidPin($pin) || $header === '' || ! hash_equals(self::signature($fields, (string) $pin), strtoupper($header))) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        ProcessCardknoxWebhook::dispatch(Arr::only($fields, self::KEPT_FIELDS));

        return response()->json(['received' => true]);
    }

    /** Lowercase names, sort, concatenate values, append PIN, non-ASCII → '?', MD5, uppercase. */
    public static function signature(array $fields, string $pin): string
    {
        $values = [];

        foreach ($fields as $name => $value) {
            $values[strtolower((string) $name)] = is_scalar($value) ? (string) $value : '';
        }

        ksort($values, SORT_STRING);

        $payload = (string) preg_replace('/[^\x00-\x7F]/u', '?', implode('', $values).$pin);

        return strtoupper(md5($payload));
    }
}

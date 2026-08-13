<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects a signed link whose `&` separators arrived HTML-escaped as `&amp;`.
 *
 * Every signed link we send is embedded in an HTML email, where escaping `&`
 * inside an href is correct. Clicking it is fine — the browser unescapes it.
 * But a link that is copied out of the message source, or rewritten by a mail
 * client or corporate link scanner, keeps the entity. The query then parses as
 * `expires` plus a parameter literally named `amp;signature`, so the signature
 * is absent and the customer is turned away from a link that was never wrong.
 *
 * Repairing the separator is not a security relaxation: the corrected URL still
 * has to pass signature validation, so a forged or genuinely altered link fails
 * exactly as before. It only stops a transport artifact from reading as tamper.
 *
 * Must run BEFORE `signed`.
 */
class RepairHtmlEscapedQueryString
{
    public function handle(Request $request, Closure $next): Response
    {
        // The raw string, not $request->getQueryString(): Symfony sorts that,
        // and the signature is computed over the parameters in their original
        // order.
        $query = (string) $request->server->get('QUERY_STRING', '');

        if (! str_contains($query, '&amp;')) {
            return $next($request);
        }

        // A repaired link must not itself be repairable, or a crafted
        // `&amp;amp;` would bounce between here and the signature check.
        $repaired = str_replace('&amp;', '&', $query);

        if ($repaired === $query || str_contains($repaired, '&amp;')) {
            return $next($request);
        }

        return redirect()->to($request->url().'?'.$repaired);
    }
}

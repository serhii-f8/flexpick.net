<?php

namespace App\Services;

use App\Constants\PartnerAttributionSource;
use App\Models\ReferralCode;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Who referred this visitor, and how that answer is remembered (spec §3).
 *
 * The code in a partner link is the referrer's personal ReferralCode. It
 * resolves to a partner *tenant* through the referrer's memberships. Before
 * registration the answer lives only in the fp_rc cookie; from registration
 * on it lives in users.partner_tenant_id, and the cookie is merely re-issued
 * from that row so a logged-out browser keeps the partner's prices.
 */
class PartnerAttributionService
{
    private const MAX_CODE_LENGTH = 64;

    public function __construct(
        private PartnerCapabilityService $partnerCapabilityService,
    ) {}

    public function cookieName(): string
    {
        return (string) config('partner.cookie_name', 'fp_rc');
    }

    /**
     * The referrer's active partner tenant. When the referrer belongs to
     * several active partner tenants the lowest id wins — a deterministic
     * choice for a configuration that is not supported (spec §3.2).
     */
    public function resolveTenantForCode(string $code): ?Tenant
    {
        /** @var User|null $referrer */
        $referrer = ReferralCode::where('code', $code)->first()?->user;

        if ($referrer === null) {
            return null;
        }

        /** @var Tenant|null $tenant */
        $tenant = $referrer->tenants()
            ->orderBy('tenants.id')
            ->get()
            ->first(fn (Tenant $candidate): bool => $this->partnerCapabilityService->tenantIsActivePartner($candidate));

        return $tenant;
    }

    /**
     * EncryptCookies has already decrypted and MAC-checked the value by the
     * time any service reads it; a forged or edited cookie never gets here.
     */
    public function cookieCode(?Request $request = null): ?string
    {
        $code = ($request ?? request())->cookie($this->cookieName());

        return $this->isPlausibleCode($code) ? $code : null;
    }

    public function hasPartnerCookie(?Request $request = null): bool
    {
        $code = $this->cookieCode($request);

        return $code !== null && $this->resolveTenantForCode($code) !== null;
    }

    public function queueCookie(string $code): void
    {
        Cookie::queue(cookie(
            name: $this->cookieName(),
            value: $code,
            minutes: (int) config('partner.cookie_lifetime_days', 365) * 24 * 60,
            path: '/',
            domain: null,
            secure: request()->isSecure() ? true : null,
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));
    }

    /**
     * Set-once. The conditional UPDATE is the race guard: two requests can
     * both read partner_tenant_id as null, but only one WHERE-null update
     * lands, and the loser leaves the row alone.
     */
    public function attribute(User $user, PartnerAttributionSource $source): void
    {
        if ($user->partner_tenant_id !== null) {
            return;
        }

        $code = $this->cookieCode();

        if ($code === null) {
            return;
        }

        $tenant = $this->resolveTenantForCode($code);

        if ($tenant === null) {
            return;
        }

        $attributes = [
            'partner_tenant_id' => $tenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => $source->value,
        ];

        $updated = User::whereKey($user->id)
            ->whereNull('partner_tenant_id')
            ->update($attributes);

        if ($updated === 0) {
            return;
        }

        $user->forceFill($attributes);
    }

    /**
     * After registration and every login: the database is the truth, so the
     * cookie is rewritten from it (spec §3.4). A user with no partner keeps
     * whatever cookie they have — there is nothing better to say.
     */
    public function refreshCookieFromDatabase(User $user): void
    {
        /** @var Tenant|null $tenant */
        $tenant = $user->partnerTenant;

        if ($tenant === null) {
            return;
        }

        $code = $this->codeForTenant($tenant);

        if ($code !== null) {
            $this->queueCookie($code);
        }
    }

    /**
     * A personal code that resolves back to this tenant: the lowest-id
     * member whose code does. ReferralService is resolved lazily because it
     * will itself consult attribution state (Task 6) and must not be a
     * constructor dependency in both directions.
     */
    public function codeForTenant(Tenant $tenant): ?string
    {
        if (! $this->partnerCapabilityService->tenantIsActivePartner($tenant)) {
            return null;
        }

        $referralService = app(ReferralService::class);

        foreach ($tenant->users()->orderBy('users.id')->get() as $member) {
            /** @var User $member */
            $code = $referralService->getOrCreateReferralCode($member)->code;

            if ($this->resolveTenantForCode($code)?->is($tenant)) {
                return $code;
            }
        }

        return null;
    }

    private function isPlausibleCode(mixed $code): bool
    {
        return is_string($code) && $code !== '' && mb_strlen($code) <= self::MAX_CODE_LENGTH;
    }
}

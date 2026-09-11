<?php

namespace Tests\Feature\Services;

use App\Constants\InvitationStatus;
use App\Constants\SessionConstants;
use App\Models\Invitation;
use App\Models\ReferralCode;
use App\Models\User;
use App\Services\PartnerAttributionService;
use App\Services\ReferralRegistrationGate;
use Tests\Feature\FeatureTest;

class ReferralRegistrationGateTest extends FeatureTest
{
    public function test_the_gate_is_open_when_the_flag_is_off(): void
    {
        config(['app.referral.only_registration' => false]);

        $this->assertFalse($this->gate()->isActive());
        $this->assertFalse($this->gate()->requiresCodeFor('stranger@example.com'));
        $this->assertFalse($this->gate()->requiresCodeInput());
    }

    public function test_an_active_gate_requires_a_code_when_nothing_is_stored(): void
    {
        config(['app.referral.only_registration' => true]);

        $this->assertTrue($this->gate()->requiresCodeFor('stranger@example.com'));
        $this->assertTrue($this->gate()->requiresCodeInput());
    }

    public function test_a_valid_code_in_the_session_satisfies_the_gate(): void
    {
        config(['app.referral.only_registration' => true]);
        $code = $this->referralCode();
        session([SessionConstants::REFERRAL_CODE => $code]);

        $this->assertSame($code, $this->gate()->storedCode());
        $this->assertFalse($this->gate()->requiresCodeFor('stranger@example.com'));
    }

    public function test_a_valid_code_in_the_referral_cookie_satisfies_the_gate(): void
    {
        config(['app.referral.only_registration' => true]);
        $code = $this->referralCode();
        request()->cookies->set($this->gate()->cookieName(), $code);

        $this->assertSame($code, $this->gate()->storedCode());
    }

    public function test_a_valid_code_in_the_partner_cookie_satisfies_the_gate(): void
    {
        config(['app.referral.only_registration' => true]);
        $code = $this->referralCode();
        request()->cookies->set(app(PartnerAttributionService::class)->cookieName(), $code);

        $this->assertSame($code, $this->gate()->storedCode());
    }

    public function test_a_code_that_does_not_exist_is_ignored(): void
    {
        config(['app.referral.only_registration' => true]);
        session([SessionConstants::REFERRAL_CODE => 'REF-NOTAREALCODE']);

        $this->assertFalse($this->gate()->isValidCode('REF-NOTAREALCODE'));
        $this->assertNull($this->gate()->storedCode());
        $this->assertTrue($this->gate()->requiresCodeFor('stranger@example.com'));
    }

    public function test_a_pending_workspace_invitation_lets_that_email_register_without_a_code(): void
    {
        config(['app.referral.only_registration' => true]);
        $this->invitation('invited@example.com');

        $this->assertTrue($this->gate()->hasPendingInvitation('Invited@Example.com'));
        $this->assertFalse($this->gate()->requiresCodeFor('invited@example.com'));
        // The form still asks for a code: the email is unknown when the page renders.
        $this->assertTrue($this->gate()->requiresCodeInput());
    }

    public function test_an_accepted_invitation_no_longer_lets_that_email_through(): void
    {
        config(['app.referral.only_registration' => true]);
        $this->invitation('accepted@example.com', ['status' => InvitationStatus::ACCEPTED->value]);

        $this->assertFalse($this->gate()->hasPendingInvitation('accepted@example.com'));
        $this->assertTrue($this->gate()->requiresCodeFor('accepted@example.com'));
    }

    public function test_an_expired_invitation_no_longer_lets_that_email_through(): void
    {
        config(['app.referral.only_registration' => true]);
        $this->invitation('expired@example.com', ['expires_at' => now()->subDay()]);

        $this->assertFalse($this->gate()->hasPendingInvitation('expired@example.com'));
        $this->assertTrue($this->gate()->requiresCodeFor('expired@example.com'));
    }

    private function gate(): ReferralRegistrationGate
    {
        return app(ReferralRegistrationGate::class);
    }

    private function referralCode(): string
    {
        $code = 'REF-'.strtoupper(uniqid());

        ReferralCode::create([
            'user_id' => User::factory()->create()->id,
            'code' => $code,
        ]);

        return $code;
    }

    private function invitation(string $email, array $attributes = []): Invitation
    {
        return Invitation::factory()->create(array_merge([
            'email' => $email,
            'user_id' => User::factory()->create()->id,
            'tenant_id' => $this->createTenant()->id,
        ], $attributes));
    }
}

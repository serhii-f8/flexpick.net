<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\Feature\FeatureTest;

class LoginControllerTest extends FeatureTest
{
    use WithFaker;

    public function test_recaptcha_is_viewed_if_enabled()
    {
        config(['app.recaptcha_enabled' => true]);

        $response = $this->get(route('login'));

        $response->assertSee('g-recaptcha');
    }

    public function test_recaptcha_is_not_viewed_if_disabled()
    {
        config(['app.recaptcha_enabled' => false]);

        $response = $this->get(route('login'));

        $response->assertDontSee('g-recaptcha');
    }

    public function test_2fa_verification_is_not_shown_when_user_has_no_2fa_enabled()
    {
        config(['app.two_factor_auth_enabled' => true]);

        $user = $this->createUser(null, [], [
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->post(route('login'), [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('dashboard'));
    }

    public function test_2fa_verification_is_shown_when_user_has_2fa_enabled()
    {
        config(['app.two_factor_auth_enabled' => true]);

        $email = $this->faker->email;

        /** @var User $user */
        $user = $this->createUser(null, [], [
            'email' => $email,
            'password' => bcrypt('password123'),
        ]);

        $user->createTwoFactorAuth();
        $user->enableTwoFactorAuth();

        $response = $this->post(route('login'), [
            'email' => $email,
            'password' => 'password123',
        ]);

        $response->assertSee('name="2fa_code"', false);  // 2FA code input field
    }

    public function test_2fa_verification_is_not_shown_when_user_has_2fa_enabled_but_2fa_is_disabled()
    {
        config(['app.two_factor_auth_enabled' => false]);

        $email = $this->faker->email;

        /** @var User $user */
        $user = $this->createUser(null, [], [
            'email' => $email,
            'password' => bcrypt('password123'),
        ]);

        $user->createTwoFactorAuth();
        $user->enableTwoFactorAuth();

        $response = $this->post(route('login'), [
            'email' => $email,
            'password' => 'password123',
        ]);

        $response->assertRedirect(route('dashboard'));
    }

    public function test_login_from_external_landing_referer_redirects_to_dashboard(): void
    {
        $email = $this->faker->email;
        $this->createUser(null, [], ['email' => $email, 'password' => bcrypt('password123')]);

        $this->get(route('login'), ['referer' => 'https://flexpick.net/']);

        $this->post(route('login'), ['email' => $email, 'password' => 'password123'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_login_from_home_referer_redirects_to_dashboard(): void
    {
        $email = $this->faker->email;
        $this->createUser(null, [], ['email' => $email, 'password' => bcrypt('password123')]);

        $this->get(route('login'), ['referer' => route('home')]);

        $this->post(route('login'), ['email' => $email, 'password' => 'password123'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_login_from_internal_page_returns_to_that_page(): void
    {
        $email = $this->faker->email;
        $this->createUser(null, [], ['email' => $email, 'password' => bcrypt('password123')]);

        $this->get(route('login'), ['referer' => route('pricing')]);

        $this->post(route('login'), ['email' => $email, 'password' => 'password123'])
            ->assertRedirect(route('pricing'));
    }

    public function test_login_after_protected_page_redirects_back_to_it(): void
    {
        $this->withExceptionHandling(); // auth middleware must redirect, not throw

        $email = $this->faker->email;
        $this->createUser(null, [], ['email' => $email, 'password' => bcrypt('password123')]);

        $this->get('/dashboard?src=email')->assertRedirect(route('login'));

        $this->post(route('login'), ['email' => $email, 'password' => 'password123'])
            ->assertRedirect(url('/dashboard?src=email'));
    }

    public function test_admin_login_redirects_to_admin_panel(): void
    {
        $email = $this->faker->email;
        $this->createUser(null, [], ['email' => $email, 'password' => bcrypt('password123'), 'is_admin' => true]);

        $this->post(route('login'), ['email' => $email, 'password' => 'password123'])
            ->assertRedirect(route('filament.admin.pages.dashboard'));
    }

    public function test_home_constant_points_to_dashboard(): void
    {
        $this->assertSame('/dashboard', RouteServiceProvider::HOME);
    }
}

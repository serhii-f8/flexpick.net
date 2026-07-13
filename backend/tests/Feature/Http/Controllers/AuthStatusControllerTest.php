<?php

namespace Tests\Feature\Http\Controllers;

use Tests\Feature\FeatureTest;

class AuthStatusControllerTest extends FeatureTest
{
    public function test_guest_is_reported_unauthenticated(): void
    {
        $this->getJson('/api/auth/status')
            ->assertOk()
            ->assertExactJson(['authenticated' => false])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_logged_in_user_is_reported_authenticated(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)
            ->getJson('/api/auth/status')
            ->assertOk()
            ->assertExactJson(['authenticated' => true]);
    }

    public function test_cors_allows_landing_origin_with_credentials(): void
    {
        $this->getJson('/api/auth/status', ['Origin' => 'http://localhost:4321'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4321')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }
}

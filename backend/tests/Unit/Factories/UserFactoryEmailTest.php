<?php

namespace Tests\Unit\Factories;

use App\Models\User;
use Tests\TestCase;

class UserFactoryEmailTest extends TestCase
{
    /**
     * The address must be unique by construction rather than by Faker's
     * unique() pool, which resets between tests while the rows it must
     * avoid are never rolled back. See spec 2026-08-02 §2.1.
     */
    public function test_email_local_part_is_a_ulid(): void
    {
        $email = User::factory()->make()->email;

        $this->assertMatchesRegularExpression(
            '/^[0-9a-hjkmnp-tv-z]{26}@example\.test$/',
            $email,
        );
    }

    public function test_a_thousand_generated_emails_are_distinct(): void
    {
        $emails = [];

        for ($i = 0; $i < 1000; $i++) {
            $emails[] = User::factory()->make()->email;
        }

        $this->assertCount(1000, array_unique($emails));
    }
}

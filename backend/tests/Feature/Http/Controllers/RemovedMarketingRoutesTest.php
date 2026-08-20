<?php

namespace Tests\Feature\Http\Controllers;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\FeatureTest;

class RemovedMarketingRoutesTest extends FeatureTest
{
    public function test_blog_routes_are_gone(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->get('/blog');
    }

    public function test_roadmap_routes_are_gone(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->get('/roadmap');
    }

    public function test_pricing_page_has_no_dead_links(): void
    {
        $this->actingAs($this->createUser());

        $response = $this->get(route('pricing'));

        $response->assertStatus(200);
        $response->assertDontSee('/blog');
        $response->assertDontSee('/roadmap');
    }
}

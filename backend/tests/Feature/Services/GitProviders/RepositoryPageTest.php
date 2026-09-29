<?php

namespace Tests\Feature\Services\GitProviders;

use App\Services\GitProviders\RepositoryEntry;
use App\Services\GitProviders\RepositoryPage;
use Tests\TestCase;

class RepositoryPageTest extends TestCase
{
    private function entries(int $count): array
    {
        return array_map(
            fn (int $i): RepositoryEntry => new RepositoryEntry("acme/repo-{$i}", "https://github.com/acme/repo-{$i}", $i % 2 === 0, 'main', null),
            range(1, $count),
        );
    }

    public function test_slices_twenty_per_page_and_reports_more(): void
    {
        $page1 = RepositoryPage::fromEntries($this->entries(45), null, 1);
        $page3 = RepositoryPage::fromEntries($this->entries(45), null, 3);

        $this->assertCount(20, $page1->items);
        $this->assertTrue($page1->hasMore);
        $this->assertSame('acme/repo-1', $page1->items[0]->fullName);
        $this->assertCount(5, $page3->items);
        $this->assertFalse($page3->hasMore);
    }

    public function test_exactly_a_full_last_page_has_no_more(): void
    {
        $page = RepositoryPage::fromEntries($this->entries(40), null, 2);

        $this->assertCount(20, $page->items);
        $this->assertFalse($page->hasMore);
    }

    public function test_search_is_a_case_insensitive_substring_on_the_full_name(): void
    {
        $all = [
            new RepositoryEntry('Acme/Billing-API', 'https://github.com/Acme/Billing-API', true, 'main', null),
            new RepositoryEntry('acme/website', 'https://github.com/acme/website', false, null, null),
        ];

        $page = RepositoryPage::fromEntries($all, '  BILLING ', 1);

        $this->assertCount(1, $page->items);
        $this->assertSame('Acme/Billing-API', $page->items[0]->fullName);
    }

    public function test_no_match_and_out_of_range_pages_are_empty_not_errors(): void
    {
        $this->assertSame([], RepositoryPage::fromEntries($this->entries(3), 'zzz', 1)->items);
        $this->assertSame([], RepositoryPage::fromEntries($this->entries(3), null, 9)->items);
        $this->assertSame('acme/repo-1', RepositoryPage::fromEntries($this->entries(3), null, 0)->items[0]->fullName); // page 0 clamps to page 1
        $this->assertSame([], RepositoryPage::empty()->items);
        $this->assertFalse(RepositoryPage::empty()->hasMore);
    }

    public function test_entry_round_trips_through_an_array_with_missing_optional_fields(): void
    {
        $entry = RepositoryEntry::fromArray(['fullName' => 'a/b', 'url' => 'https://github.com/a/b', 'private' => true]);

        $this->assertSame('a/b', $entry->fullName);
        $this->assertTrue($entry->private);
        $this->assertNull($entry->defaultBranch);
        $this->assertNull($entry->updatedAt);
        $this->assertEquals($entry, RepositoryEntry::fromArray($entry->toArray()));
    }
}

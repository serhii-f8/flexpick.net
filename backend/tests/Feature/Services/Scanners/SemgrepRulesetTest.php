<?php

namespace Tests\Feature\Services\Scanners;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\FeatureTest;

class SemgrepRulesetTest extends FeatureTest
{
    private const DIMENSIONS = ['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene'];

    /** @return list<array{file: string, rule: array<string, mixed>}> */
    private function rules(): array
    {
        $rules = [];

        foreach ((new Finder)->files()->in(resource_path('semgrep/flexpick'))->name('*.yaml') as $file) {
            foreach (Yaml::parseFile($file->getRealPath())['rules'] ?? [] as $rule) {
                $rules[] = ['file' => $file->getRelativePathname(), 'rule' => $rule];
            }
        }

        return $rules;
    }

    public function test_the_ruleset_is_not_empty(): void
    {
        $this->assertNotEmpty($this->rules(), 'No Semgrep rules found — tier 1 would ship with no SAST signal.');
    }

    public function test_every_rule_declares_a_family(): void
    {
        foreach ($this->rules() as $entry) {
            $this->assertArrayHasKey(
                'family',
                $entry['rule']['metadata'] ?? [],
                "Rule {$entry['rule']['id']} in {$entry['file']} declares no metadata.family; it could not be grouped.",
            );
        }
    }

    public function test_every_rule_declares_a_known_dimension(): void
    {
        foreach ($this->rules() as $entry) {
            $dimension = $entry['rule']['metadata']['dimension'] ?? null;

            $this->assertContains(
                $dimension,
                self::DIMENSIONS,
                "Rule {$entry['rule']['id']} in {$entry['file']} declares dimension [{$dimension}]; "
                .'an unmapped rule narrates in the report but affects no score.',
            );
        }
    }

    public function test_rule_ids_are_unique_and_namespaced(): void
    {
        $ids = array_map(fn (array $entry): string => $entry['rule']['id'], $this->rules());

        $this->assertSame(array_unique($ids), $ids, 'Duplicate Semgrep rule id.');

        foreach ($ids as $id) {
            $this->assertStringStartsWith('flexpick.', $id, "Rule id [{$id}] is not namespaced to flexpick.");
        }
    }

    public function test_every_rule_declares_a_severity(): void
    {
        foreach ($this->rules() as $entry) {
            $this->assertContains(
                $entry['rule']['severity'] ?? null,
                ['ERROR', 'WARNING', 'INFO'],
                "Rule {$entry['rule']['id']} declares no valid severity.",
            );
        }
    }
}

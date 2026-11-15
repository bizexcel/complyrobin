<?php

use Symfony\Component\Yaml\Yaml;

/**
 * The Boost project rules, guideline and skills are part of the codebase's contract with
 * AI agents and new developers: keep them well-formed and indexed.
 */
function frontmatter(string $path): array
{
    $content = file_get_contents($path);

    expect($content)->toStartWith("---\n", "{$path} must start with YAML frontmatter");

    preg_match('/\A---\n(.*?)\n---\n/s', $content, $match);

    return $match ? Yaml::parse($match[1]) : [];
}

$root = dirname(__DIR__, 2);

it('has the expected project rules', function () use ($root): void {
    $rules = array_map(basename(...), glob("{$root}/.ai/rules/*.md"));

    expect($rules)->toContain(
        'migrations.md', 'models.md', 'actions.md', 'filament.md', 'tenancy.md',
        'billing.md', 'http-routing.md', 'pdf.md', 'seeders.md', 'testing.md', 'index.md',
    );
});

it('scopes every rule to file paths and lists it in the index', function () use ($root): void {
    $index = file_get_contents("{$root}/.ai/rules/index.md");

    foreach (glob("{$root}/.ai/rules/*.md") as $rule) {
        if (basename($rule) === 'index.md') {
            continue;
        }

        expect(frontmatter($rule)['paths'] ?? [])->not->toBeEmpty(basename($rule).' needs paths')
            ->and($index)->toContain('.ai/rules/'.basename($rule));
    }
});

it('gives every skill a name and description', function () use ($root): void {
    $skills = glob("{$root}/.ai/skills/*/SKILL.md");

    expect($skills)->toHaveCount(3);

    foreach ($skills as $skill) {
        $meta = frontmatter($skill);

        expect($meta['name'] ?? null)->toBe(basename(dirname($skill)))
            ->and($meta['description'] ?? '')->not->toBeEmpty();
    }
});

it('loads the ComplyRobin guideline into CLAUDE.md and AGENTS.md', function () use ($root): void {
    foreach (['CLAUDE.md', 'AGENTS.md'] as $file) {
        expect(file_get_contents("{$root}/{$file}"))->toContain('ComplyRobin project guideline');
    }
});

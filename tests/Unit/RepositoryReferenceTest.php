<?php

namespace Tests\Unit;

use App\Support\RepositoryReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RepositoryReferenceTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function references(): array
    {
        return [
            'owner/repo' => ['acme/app', 'acme/app'],
            'web url' => ['https://github.com/acme/app', 'acme/app'],
            'web url with .git' => ['https://github.com/acme/app.git', 'acme/app'],
            'page url' => ['https://github.com/acme/app/tree/main/src', 'acme/app'],
            'ssh url' => ['git@github.com:acme/app.git', 'acme/app'],
            'gitlab subgroup' => ['https://gitlab.com/group/sub/app', 'group/sub/app'],
            'gitlab page url' => ['https://gitlab.com/group/app/-/merge_requests', 'group/app'],
            'surrounding space' => ['  acme/app/  ', 'acme/app'],
            'just a name' => ['app', null],
            'spaces inside' => ['acme/my app', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('references')]
    public function test_it_parses_repository_references(string $input, ?string $expected)
    {
        $this->assertSame($expected, RepositoryReference::parse($input));
    }
}

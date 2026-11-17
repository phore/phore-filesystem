<?php

declare(strict_types=1);

namespace Test;

use PHPUnit\Framework\TestCase;

final class FormatUriTest extends TestCase
{
    public function testDefaultCleansWhileCleanFalsePreservesOriginal(): void
    {
        $cases = [
            '.' => '.',
            '/' => '/',
            'users' => 'users',
            'users/' => 'users/',
            '/users/' => '/users/',
            '//users///' => '/users/',
            '/users//profile' => '/users/profile',
            '/a/./b/../c/' => '/a/c/',
        ];

        foreach ($cases as $input => $expected) {
            $uri = phore_uri($input);
            self::assertSame($expected, $uri->formatUri(), $input);
            self::assertSame($input, $uri->formatUri(clean: false), $input);
            self::assertSame($input, $uri->getUri());
        }
    }

    public function testLeadingSlashFormatting(): void
    {
        self::assertSame('/users/', phore_uri('users/')->formatUri(leadingSlash: true));
        self::assertSame('/users/', phore_uri('///users/')->formatUri(leadingSlash: true));
        self::assertSame('users/', phore_uri('///users/')->formatUri(leadingSlash: false));
        self::assertSame('users', phore_uri('users')->formatUri(leadingSlash: false));
    }

    public function testTrailingSlashFormatting(): void
    {
        self::assertSame('users/', phore_uri('users///')->formatUri(trailingSlash: true));
        self::assertSame('users', phore_uri('users///')->formatUri(trailingSlash: false));
        self::assertSame('/users/', phore_uri('/users')->formatUri(trailingSlash: true));
        self::assertSame('/users', phore_uri('/users/')->formatUri(trailingSlash: false));
    }

    public function testCombinedOptionsNeverDuplicateEnforcedBoundarySlashes(): void
    {
        self::assertSame('/users/', phore_uri('///users///')->formatUri(true, true));
        self::assertSame('/users', phore_uri('///users///')->formatUri(true, false));
        self::assertSame('users/', phore_uri('///users///')->formatUri(false, true));
        self::assertSame('users', phore_uri('///users///')->formatUri(false, false));
        self::assertSame('/users/', phore_uri('users')->formatUri(true, true));
        self::assertSame('/users/profile/', phore_uri('/users//profile')->formatUri(true, true));
    }

    public function testCleaningCanBeDisabledWithoutChangingBoundaryFormatting(): void
    {
        $uri = phore_uri('/app/./users//profile/');

        self::assertSame('/app/users/profile/', $uri->formatUri(leadingSlash: true));
        self::assertSame('/app/./users//profile/', $uri->formatUri(
            leadingSlash: true,
            clean: false
        ));
        self::assertSame('app/./users//profile/', $uri->formatUri(
            leadingSlash: false,
            trailingSlash: true,
            clean: false
        ));
        self::assertSame('/app/./users//profile/', $uri->getUri());
    }

    public function testRelativeBaseDotUsesRootRepresentation(): void
    {
        $relative = phore_uri('/app')->rel('/app');

        self::assertSame('.', $relative->formatUri());
        self::assertSame('/', $relative->formatUri(leadingSlash: true));
        self::assertSame('/', $relative->formatUri(leadingSlash: true, trailingSlash: true));
        self::assertSame('/', $relative->formatUri(leadingSlash: true, trailingSlash: false));
        self::assertSame('./', $relative->formatUri(trailingSlash: true));
        self::assertSame('./', $relative->formatUri(leadingSlash: false, trailingSlash: true));
        self::assertSame('.', $relative->formatUri(leadingSlash: false, trailingSlash: false));
        self::assertSame('.', $relative->getUri());
    }

    public function testRootPathIsNeverDuplicatedWhenFormattingBothBoundaries(): void
    {
        self::assertSame('/', phore_uri('/')->formatUri(true, true));
        self::assertSame('/', phore_uri('/')->formatUri(true, false));
        self::assertSame('/', phore_uri('///')->formatUri(true, true));
        self::assertSame('', phore_uri('/')->formatUri(false, false));
    }

    public function testRelativeUrlPathFormattingAndNonMutation(): void
    {
        $relative = phore_uri('/app/users')->rel('/app');

        self::assertSame('/users', $relative->formatUri(leadingSlash: true));
        self::assertSame('/users/', $relative->formatUri(leadingSlash: true, trailingSlash: true));
        self::assertSame('users', $relative->getUri());

        $withTrailingSlash = phore_uri('/app/users/')->rel('/app');
        self::assertSame('users', $withTrailingSlash->getUri());
        self::assertSame('/users/', $withTrailingSlash->formatUri(true, true));
    }
}

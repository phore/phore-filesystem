<?php

declare(strict_types=1);

namespace Test;

use Phore\FileSystem\Exception\PathOutOfBoundsException;
use PHPUnit\Framework\TestCase;

final class AssertCleanTest extends TestCase
{
    public function testAcceptsLiteralCleanPathsWithoutNormalizing(): void
    {
        foreach (['/', '/demo/', '/demo/assets/app.js', '/a.../b', 'relative/file', 'a%2e%2e/b'] as $value) {
            $uri = phore_uri($value);
            self::assertSame($uri, $uri->assertClean());
            self::assertSame($value, $uri->getUri());
        }
    }

    public function testRejectsUncleanPaths(): void
    {
        foreach (['/a/../b', '/a/./b', './a', '../a', '/a//b', '//a', '/a\\b', "/a\n/b", '/a/..', '/a/.'] as $value) {
            try {
                phore_uri($value)->assertClean();
                self::fail("Accepted unclean URI: " . json_encode($value));
            } catch (PathOutOfBoundsException $exception) {
                self::assertStringContainsString('not clean', $exception->getMessage());
            }
        }
    }

    public function testIsCleanMatchesAssertCleanWithoutChangingUri(): void
    {
        foreach (['/', '/demo/', '/demo/assets/app.js', 'relative/file', '/a.../b', 'a%2e%2e/b'] as $value) {
            $uri = phore_uri($value);
            self::assertTrue($uri->isClean(), $value);
            self::assertSame($uri, $uri->assertClean());
            self::assertSame($value, $uri->getUri());
        }

        foreach (['/a/../b', '/a/./b', '../a', '/a//b', '/a\\b', "/a\n/b"] as $value) {
            $uri = phore_uri($value);
            self::assertFalse($uri->isClean(), $value);
            self::assertSame($value, $uri->getUri());
        }
    }

    public function testThrowsProvidedExceptionInstance(): void
    {
        $custom = new \InvalidArgumentException('Invalid incoming request');
        try {
            phore_uri('/a/../b')->assertClean($custom);
            self::fail('Expected the supplied exception');
        } catch (\InvalidArgumentException $caught) {
            self::assertSame($custom, $caught);
        }
    }

    public function testDoesNotQueryFilesystemOrFollowSymlinks(): void
    {
        $uri = phore_uri('/definitely/nonexistent/path/');
        self::assertSame($uri, $uri->assertClean());
    }
}

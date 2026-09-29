<?php

namespace Test;

use Phore\FileSystem\Exception\FilesystemException;
use Phore\FileSystem\Exception\FilesystemPolicyViolationException;
use Phore\FileSystem\Exception\InvalidFilesystemOptionsException;
use Phore\FileSystem\Exception\PathOutOfBoundsException;
use Phore\FileSystem\Exception\SymlinkNotAllowedException;
use Phore\FileSystem\Exception\UnsupportedFilesystemPolicyException;
use Phore\FileSystem\FilesystemOptions;
use Phore\FileSystem\PhoreTempDir;
use PHPUnit\Framework\TestCase;

class FilesystemSecurityTest extends TestCase
{
    public function testRootBoundaryRejectsSiblingAndAllowsOwnTree(): void
    {
        $base = new PhoreTempDir();
        $allowed = $base->withSubPath('allowed')->assertDirectory(true);
        $sibling = $base->withSubPath('sibling')->assertDirectory(true);
        $sibling->withSubPath('secret.txt')->asFile()->set_contents('secret');

        $root = phore_dir($allowed, ['rootDir' => (string) $allowed]);
        $root->withSubPath('inside.txt')->asFile()->set_contents('inside');

        $this->assertSame(
            'inside',
            $root->withSubPath('inside.txt')->asFile()->get_contents()
        );

        $this->expectException(PathOutOfBoundsException::class);
        $root->withRelativePath('../sibling/secret.txt')->asFile()->get_contents();
    }

    public function testRestrictionsSurviveFactoryAndTypeRoundTrips(): void
    {
        $base = new PhoreTempDir();
        $allowed = $base->withSubPath('allowed')->assertDirectory(true);
        $sibling = $base->withSubPath('sibling')->assertDirectory(true);
        $allowed->withSubPath('inside.txt')->asFile()->set_contents('inside');
        $sibling->withSubPath('secret.txt')->asFile()->set_contents('secret');

        $root = phore_dir($allowed, ['rootDir' => (string) $allowed]);
        $file = phore_file($root->withSubPath('inside.txt'));
        $roundTrip = phore_file(phore_dir(phore_uri($file)));

        $this->assertSame('inside', $roundTrip->get_contents());
        $this->assertSame((string) $allowed, $roundTrip->getFilesystemOptions()->rootDir);
        $this->assertTrue($roundTrip->getFilesystemOptions()->followSymlinks);

        $this->expectException(PathOutOfBoundsException::class);
        $roundTrip->withRelativePath('../sibling/secret.txt')->asFile()->get_contents();
    }

    public function testInheritedRestrictionsCannotBeRelaxed(): void
    {
        $base = new PhoreTempDir();
        $allowed = $base->withSubPath('allowed')->assertDirectory(true);
        $file = $allowed->withSubPath('inside.txt')->asFile()->set_contents('inside');
        $bounded = phore_file($file, [
            'rootDir' => (string) $allowed,
            'followSymlinks' => false,
        ]);

        try {
            phore_file($bounded, ['rootDir' => null]);
            $this->fail('Expected root reset to be rejected.');
        } catch (FilesystemPolicyViolationException) {
        }

        $this->expectException(FilesystemPolicyViolationException::class);
        phore_file($bounded, ['followSymlinks' => true]);
    }

    public function testCompleteOptionsSnapshotCannotResetInheritedRoot(): void
    {
        $base = new PhoreTempDir();
        $allowed = $base->withSubPath('allowed')->assertDirectory(true);
        $file = $allowed->withSubPath('inside.txt')->asFile()->set_contents('inside');
        $bounded = phore_file($file, ['rootDir' => (string) $allowed]);

        $this->expectException(FilesystemPolicyViolationException::class);
        phore_file($bounded, FilesystemOptions::fromAssoc([]));
    }

    public function testDefaultPolicyFollowsSymlink(): void
    {
        $base = new PhoreTempDir();
        $target = $base->withSubPath('target.txt')->asFile()->set_contents('target');
        $link = (string) $base . '/link.txt';

        if (!@symlink((string) $target, $link)) {
            $this->markTestSkipped('Symlinks are not available on this platform.');
        }

        try {
            $this->assertSame('target', phore_file($link)->get_contents());
        } finally {
            @unlink($link);
        }
    }

    public function testExplicitNoFollowRejectsSymlink(): void
    {
        $base = new PhoreTempDir();
        $target = $base->withSubPath('target.txt')->asFile()->set_contents('target');
        $link = (string) $base . '/link.txt';

        if (!@symlink((string) $target, $link)) {
            $this->markTestSkipped('Symlinks are not available on this platform.');
        }

        try {
            $this->expectException(SymlinkNotAllowedException::class);
            phore_file($link, ['followSymlinks' => false])->get_contents();
        } finally {
            @unlink($link);
        }
    }

    public function testRootAllowsOnlyInternalSymlinkByDefault(): void
    {
        $base = new PhoreTempDir();
        $allowed = $base->withSubPath('allowed')->assertDirectory(true);
        $sibling = $base->withSubPath('sibling')->assertDirectory(true);
        $target = $allowed->withSubPath('target.txt')->asFile()->set_contents('inside');
        $outside = $sibling->withSubPath('secret.txt')->asFile()->set_contents('outside');
        $internalLink = (string) $allowed . '/internal.txt';
        $externalLink = (string) $allowed . '/external.txt';

        if (!@symlink((string) $target, $internalLink)) {
            $this->markTestSkipped('Symlinks are not available on this platform.');
        }
        if (!@symlink((string) $outside, $externalLink)) {
            @unlink($internalLink);
            $this->markTestSkipped('Symlinks are not available on this platform.');
        }

        try {
            $options = [
                'rootDir' => (string) $allowed,
            ];

            $this->assertSame('inside', phore_file($internalLink, $options)->get_contents());

            try {
                phore_file($externalLink, $options)->get_contents();
                $this->fail('Expected external symlink to be rejected.');
            } catch (PathOutOfBoundsException) {
            }
        } finally {
            @unlink($internalLink);
            @unlink($externalLink);
        }
    }

    public function testHardLinkRestrictionRejectsMultiplyLinkedFile(): void
    {
        $base = new PhoreTempDir();
        $allowed = $base->withSubPath('allowed')->assertDirectory(true);
        $target = $allowed->withSubPath('target.txt')->asFile()->set_contents('inside');
        $hardLink = (string) $allowed . '/hard.txt';

        if (!@link((string) $target, $hardLink)) {
            $this->markTestSkipped('Hard links are not available on this platform.');
        }

        try {
            $this->expectException(FilesystemPolicyViolationException::class);
            phore_file((string) $target, [
                'rootDir' => (string) $allowed,
                'allowHardLinks' => false,
            ]);
        } finally {
            @unlink($hardLink);
        }
    }

    public function testAtomicContainmentFailsClosedWithoutBackend(): void
    {
        $base = new PhoreTempDir();
        $allowed = $base->withSubPath('allowed')->assertDirectory(true);
        $file = $allowed->withSubPath('inside.txt')->asFile()->set_contents('inside');

        $bounded = phore_file((string) $file, [
            'rootDir' => (string) $allowed,
            'requireAtomicContainment' => true,
        ]);

        $this->expectException(UnsupportedFilesystemPolicyException::class);
        $bounded->get_contents();
    }

    public function testWalkReturnsGloballySortedFilesRelativeToExplicitBase(): void
    {
        $base = new PhoreTempDir();
        $allowed = $base->withSubPath('allowed')->assertDirectory(true);
        $allowed->withSubPath('10')->asFile()->set_contents('10');
        $allowed->withSubPath('2')->asFile()->set_contents('2');
        $allowed->withSubPath('a.txt')->asFile()->set_contents('a');
        $allowed->withSubPath('a/x.md')->asFile()->mkdir()->set_contents('x');

        $root = phore_dir($allowed, ['rootDir' => (string) $allowed]);
        $paths = array_map(
            static fn($file): string => $file->getRelPath($root),
            $root->listFiles(recursive: true, sort: 'path')
        );

        $this->assertSame(['10', '2', 'a.txt', 'a/x.md'], $paths);
    }

    public function testWalkChecksSymlinkBeforeFilenameFilter(): void
    {
        $base = new PhoreTempDir();
        $allowed = $base->withSubPath('allowed')->assertDirectory(true);
        $sibling = $base->withSubPath('sibling')->assertDirectory(true);
        $outside = $sibling->withSubPath('secret.txt')->asFile()->set_contents('outside');
        $allowed->withSubPath('page.md')->asFile()->set_contents('page');
        $link = (string) $allowed . '/ignored.bin';

        if (!@symlink((string) $outside, $link)) {
            $this->markTestSkipped('Symlinks are not available on this platform.');
        }

        $root = phore_dir($allowed, ['rootDir' => (string) $allowed]);

        try {
            $this->expectException(PathOutOfBoundsException::class);
            $root->listFiles('*.md', recursive: true);
        } finally {
            @unlink($link);
        }
    }

    public function testRecursiveWalkReportsDepthLimitInsteadOfReturningPartialResult(): void
    {
        $base = new PhoreTempDir();
        $allowed = $base->withSubPath('allowed')->assertDirectory(true);
        $allowed->withSubPath('sub/deep.txt')->asFile()->mkdir()->set_contents('deep');
        $root = phore_dir($allowed, ['rootDir' => (string) $allowed]);

        $this->expectException(FilesystemException::class);
        $root->listFiles(recursive: true, recursionLimit: 0);
    }

    public function testAssertFileTargetDoesNotCreateFileOrParents(): void
    {
        $base = new PhoreTempDir();
        $allowed = $base->withSubPath('allowed')->assertDirectory(true);
        $root = phore_dir($allowed, ['rootDir' => (string) $allowed]);

        $target = $root->withSubPath('new/sub/file.txt')->assertFileTarget();

        $this->assertFalse(file_exists((string) $target));
        $this->assertFalse(file_exists((string) $allowed . '/new'));
    }

    public function testOptionsRejectUnknownKeysAndLooseBooleanValues(): void
    {
        try {
            FilesystemOptions::fromAssoc(['rootPath' => '/tmp']);
            $this->fail('Expected unknown key to be rejected.');
        } catch (InvalidFilesystemOptionsException) {
        }

        $this->expectException(InvalidFilesystemOptionsException::class);
        FilesystemOptions::fromAssoc(['followSymlinks' => 'false']);
    }

    public function testRootDirRejectsParentSegmentsBeforeNormalization(): void
    {
        $this->expectException(InvalidFilesystemOptionsException::class);
        FilesystemOptions::fromAssoc(['rootDir' => '/srv/site/link/../docs']);
    }

    public function testFileExtensionCannotInjectPathSegmentsEvenWithStrictChecksDisabled(): void
    {
        $base = new PhoreTempDir();
        $allowed = $base->withSubPath('allowed')->assertDirectory(true);
        $file = phore_file(
            $allowed->withSubPath('page.md'),
            ['rootDir' => (string) $allowed]
        );

        $this->expectException(\InvalidArgumentException::class);
        $file->withFileExtension('../secret', strictChecks: false);
    }

    public function testStreamCloseReturnsFileWithSameRestrictions(): void
    {
        $base = new PhoreTempDir();
        $allowed = $base->withSubPath('allowed')->assertDirectory(true);
        $sibling = $base->withSubPath('sibling')->assertDirectory(true);
        $allowed->withSubPath('inside.txt')->asFile()->set_contents('inside');
        $sibling->withSubPath('secret.txt')->asFile()->set_contents('secret');

        $file = phore_file((string) $allowed . '/inside.txt', [
            'rootDir' => (string) $allowed,
        ]);
        $stream = $file->fopen('r');
        $returned = $stream->fclose();

        $this->assertSame((string) $allowed, $returned->getFilesystemOptions()->rootDir);

        $this->expectException(PathOutOfBoundsException::class);
        $returned->withRelativePath('../sibling/secret.txt')->asFile()->get_contents();
    }
}

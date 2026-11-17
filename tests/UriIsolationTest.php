<?php

declare(strict_types=1);

namespace Phore\FileSystem {
    /**
     * Die Spies werden ausschließlich im isolierten PHPUnit-Prozess installiert.
     */
    function installUriIsolationProbe(): void
    {
        if (\Test\UriIsolationProbe::$installed) {
            return;
        }
        \Test\UriIsolationProbe::$installed = true;

        function getcwd(): string|false
        {
            \Test\UriIsolationProbe::record('getcwd');
            return \getcwd();
        }

        function file_exists(string $filename): bool
        {
            \Test\UriIsolationProbe::record('file_exists', $filename);
            return \file_exists($filename);
        }

        function is_link(string $filename): bool
        {
            \Test\UriIsolationProbe::record('is_link', $filename);
            return \is_link($filename);
        }

        function realpath(string $path): string|false
        {
            \Test\UriIsolationProbe::record('realpath', $path);
            return \realpath($path);
        }

        function stat(string $filename): array|false
        {
            \Test\UriIsolationProbe::record('stat', $filename);
            return \stat($filename);
        }

        function lstat(string $filename): array|false
        {
            \Test\UriIsolationProbe::record('lstat', $filename);
            return \lstat($filename);
        }

        function is_dir(string $filename): bool
        {
            \Test\UriIsolationProbe::record('is_dir', $filename);
            return \is_dir($filename);
        }

        function is_file(string $filename): bool
        {
            \Test\UriIsolationProbe::record('is_file', $filename);
            return \is_file($filename);
        }

        function is_readable(string $filename): bool
        {
            \Test\UriIsolationProbe::record('is_readable', $filename);
            return \is_readable($filename);
        }

        function is_writable(string $filename): bool
        {
            \Test\UriIsolationProbe::record('is_writable', $filename);
            return \is_writable($filename);
        }

        function readlink(string $path): string|false
        {
            \Test\UriIsolationProbe::record('readlink', $path);
            return \readlink($path);
        }
    }
}

namespace Test {
    use Phore\FileSystem\Exception\FileNotFoundException;
    use Phore\FileSystem\Exception\FilesystemException;
    use Phore\FileSystem\Exception\FilesystemPolicyViolationException;
    use Phore\FileSystem\Exception\PathOutOfBoundsException;
    use Phore\FileSystem\Exception\SymlinkNotAllowedException;
    use Phore\FileSystem\FilesystemOptions;
    use Phore\FileSystem\PhoreUri;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    final class UriIsolationProbeViolation extends \LogicException
    {
    }

    final class UriIsolationProbe
    {
        public static bool $installed = false;
        public static bool $armed = false;

        /** @var list<array{string, string|null}> */
        public static array $calls = [];

        public static function record(string $function, ?string $path = null): void
        {
            if (!self::$armed) {
                return;
            }
            self::$calls[] = [$function, $path];
            throw new UriIsolationProbeViolation("Unexpected filesystem probe: $function");
        }
    }

    /**
     * Sicherheitsrelevante Regressionstests: Änderungen und Deaktivierungen
     * benötigen eine ausdrücklich benannte manuelle Freigabe.
     * Die Spies prüfen die Filesystem-Einstiegspunkte der Bibliothek, nicht die
     * Dateizugriffe des Autoloaders oder des PHPUnit-Prozesses.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class UriIsolationTest extends TestCase
    {
        private ?string $fixtureDirectory = null;

        protected function setUp(): void
        {
            // Vor dem ersten Bibliotheksaufruf die Namespace-Auflösung festlegen.
            \Phore\FileSystem\installUriIsolationProbe();
            UriIsolationProbe::$armed = false;
            UriIsolationProbe::$calls = [];
        }

        protected function tearDown(): void
        {
            UriIsolationProbe::$armed = false;
            if ($this->fixtureDirectory !== null) {
                $this->removeFixture($this->fixtureDirectory);
            }
        }

        public static function defaultPaths(): iterable
        {
            yield 'absolute dot segments' => ['/virtual/site/a/./b/../file.txt', '/virtual/site/a/file.txt'];
            yield 'duplicate slash' => ['/virtual/site//assets/app.js', '/virtual/site/assets/app.js'];
            yield 'trailing slash' => ['/virtual/site/assets/', '/virtual/site/assets'];
            yield 'relative path' => ['relative/./folder/../file.txt', 'relative/file.txt'];
            yield 'leading relative parent' => ['../folder/../file.txt', '../file.txt'];
            yield 'multiple leading relative parents' => ['../../file.txt', '../../file.txt'];
            yield 'encoded filenames' => ['/site/%2e/%2f/%252e/file', '/site/%2e/%2f/%252e/file'];
            yield 'empty segment before parent' => ['/a//../b', '/b'];
            yield 'last parent segment' => ['/a/b/..', '/a'];
            yield 'root clamp' => ['/../../file', '/file'];
            yield 'root' => ['/', '/'];
        }

        #[DataProvider('defaultPaths')]
        public function testDefaultCleaningOnlyChangesTheDisplayWithoutFilesystemAccess(
            string $input,
            string $expected
        ): void {
            $this->withoutFilesystem(function () use ($input, $expected): void {
                $uri = new PhoreUri($input);
                $clean = $uri->clean();

                self::assertSame($input, $uri->getUri());
                self::assertSame($expected, $clean->getUri());
                self::assertSame($expected, (string) phore_uri($clean));
                self::assertSame($expected, $clean->clean()->getUri());
                self::assertNotSame($uri, $clean);
            });
        }

        public function testPureDerivationsAndExplicitBaseNeverInspectTheFilesystem(): void
        {
            // Auch relative URIs benötigen ohne echten Zugriff kein getcwd().
            $this->withoutFilesystem(function (): void {
                $uri = phore_uri('/virtual/site/main.ts');
                self::assertSame('/virtual/site', $uri->getDirname()->getUri());
                self::assertSame('main.ts', $uri->getBasename());
                self::assertSame('main', $uri->getFilename());
                self::assertSame('ts', $uri->getExtension());
                self::assertTrue($uri->fnmatch('*.ts'));
                self::assertTrue($uri->isSubpathOf('/virtual/site'));
                self::assertFalse($uri->isSubpathOf('/virtual/site-other'));
                self::assertSame('main.ts', $uri->rel('/virtual/site')->getUri());

                $base = phore_uri('relative/base');
                $child = $base->withSubPath('child/../leaf.txt');
                self::assertSame('relative/base/leaf.txt', $child->getUri());
                self::assertSame('leaf.txt', $child->getRelPath());
                self::assertSame('relative/other.txt', $base->withRelativePath('../other.txt')->getUri());
                self::assertSame('relative/base/one/two.txt', $base->join('one', 'two.txt')->getUri());
                self::assertSame('relative/base/safe+value', $base->join_secure('safe value')->getUri());
                self::assertSame('relative/base', $base->rel('/unused')->getUri());
                self::assertSame('/explicit/relative/base', $base->abs('/explicit')->getUri());
                self::assertSame('/virtual/site/main.ts', $uri->abs('/unused')->getUri());
            });
        }

        public function testDeferredOptionsAndCloningDoNotBindANonexistentRoot(): void
        {
            $root = $this->fixture() . '/not-created';
            self::assertDirectoryDoesNotExist($root);

            $uri = $this->withoutFilesystem(function () use ($root): PhoreUri {
                $options = [
                    'rootDir' => $root,
                    'followSymlinks' => false,
                    'allowHardLinks' => false,
                    'requireAtomicContainment' => true,
                ];
                $uri = phore_uri($root . '/inside.txt', $options);
                $copy = phore_uri(phore_uri($uri, []), null)->clean();
                self::assertSame($options, $copy->getFilesystemOptions()->toArray());
                self::assertSame($root, $copy->getDirname()->getUri());

                return $copy;
            });

            $this->expectException(FileNotFoundException::class);
            $uri->asFile();
        }

        public static function invalidSubpaths(): iterable
        {
            foreach (['', '../outside', 'a/../../outside', '/', '/outside', '//host/path', '.', 'a/..', 'https://host/path', 'a\\b', "a\0b"] as $path) {
                yield bin2hex($path) => [$path];
            }
        }

        #[DataProvider('invalidSubpaths')]
        public function testLexicalSubpathRejectionsNeedNoFilesystemAccess(string $path): void
        {
            $this->expectException(PathOutOfBoundsException::class);
            $this->withoutFilesystem(static fn () => phore_uri('/virtual/site')->withSubPath($path));
        }

        public static function urlPaths(): iterable
        {
            yield 'root' => ['/', '/'];
            yield 'dot' => ['/demo/./src/main.ts', '/demo/src/main.ts'];
            yield 'parent' => ['/demo/../src/main.ts', '/src/main.ts'];
            yield 'root clamp' => ['/../../src/main.ts', '/src/main.ts'];
            yield 'empty segment before parent' => ['/demo/a//../main.ts', '/demo/a/main.ts'];
            yield 'duplicate slash preserved' => ['/demo//main.ts', '/demo//main.ts'];
            yield 'trailing slash preserved' => ['/demo/', '/demo/'];
            yield 'last dot' => ['/demo/a/.', '/demo/a/'];
            yield 'last parent' => ['/demo/a/..', '/demo/'];
            yield 'last parent with slash' => ['/demo/a/../', '/demo/'];
            yield 'dot with trailing slash' => ['/demo/./', '/demo/'];
            yield 'encoded dot' => ['/demo/%2e/src/main.ts', '/demo/src/main.ts'];
            yield 'encoded parent mixed case' => ['/demo/%2E%2e/main.ts', '/main.ts'];
            yield 'literal encoded parent' => ['/demo/.%2e/main.ts', '/main.ts'];
            yield 'encoded literal parent' => ['/demo/%2e./main.ts', '/main.ts'];
            yield 'encoded trailing dot' => ['/demo/%2E', '/demo/'];
            yield 'encoded trailing parent' => ['/demo/a/%2e%2E', '/demo/'];
            yield 'double encoding untouched' => ['/demo/%252e/main.ts', '/demo/%252e/main.ts'];
            yield 'encoded navigation separator untouched' => ['/demo/a%2F..%2Fmain.ts', '/demo/a%2F..%2Fmain.ts'];
            yield 'encoded slash untouched' => ['/demo/%2Fmain.ts', '/demo/%2Fmain.ts'];
            yield 'encoded backslash untouched' => ['/demo/%5Cmain.ts', '/demo/%5Cmain.ts'];
            yield 'dot inside name untouched' => ['/demo/%2efile.ts', '/demo/%2efile.ts'];
            yield 'encoded delimiters untouched' => ['/demo/a%3Fb%23c.ts', '/demo/a%3Fb%23c.ts'];
            yield 'literal spaces and plus preserved' => ['/demo/a b+c.ts', '/demo/a b+c.ts'];
            yield 'unicode preserved' => ['/demo/ä/日本.ts', '/demo/ä/日本.ts'];
        }

        #[DataProvider('urlPaths')]
        public function testUrlPathCleaningPreservesBrowserPathSemanticsWithoutFilesystemAccess(
            string $input,
            string $expected
        ): void {
            $this->withoutFilesystem(function () use ($input, $expected): void {
                $uri = phore_uri($input);
                self::assertSame($expected, $uri->clean(urlPath: true)->getUri());
                self::assertSame($input, $uri->getUri());
            });
        }

        public static function invalidUrlPaths(): iterable
        {
            foreach (['', 'relative/main.ts', './main.ts', '../main.ts', 'https://host/main.ts', '//host/main.ts', '/demo/main.ts?q=1', '/demo/main.ts#fragment', '/demo\\main.ts', "/demo/\tmain.ts", "/demo/\rmain.ts", "/demo/\nmain.ts", "/demo/\x01main.ts", "/demo/\x7fmain.ts"] as $path) {
                yield bin2hex($path) => [$path];
            }
        }

        #[DataProvider('invalidUrlPaths')]
        public function testUrlPathModeRejectsNonPathComponentsWithoutFilesystemAccess(string $path): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->withoutFilesystem(static fn () => phore_uri($path)->clean(urlPath: true));
        }

        public function testConstructorRejectsNulWithoutFilesystemAccess(): void
        {
            $this->expectException(FilesystemException::class);
            $this->withoutFilesystem(static fn () => phore_uri("/demo/\0main.ts"));
        }

        public static function filesystemTypes(): iterable
        {
            yield 'file' => ['file'];
            yield 'directory' => ['directory'];
        }

        #[DataProvider('filesystemTypes')]
        public function testUrlModeCannotReinterpretAFileOrDirectory(string $type): void
        {
            $root = $this->fixture() . '/scope';
            $object = $type === 'file' ? phore_file($root . '/inside.txt') : phore_dir($root);

            $this->expectException(\InvalidArgumentException::class);
            $this->withoutFilesystem(static fn () => $object->clean(urlPath: true));
        }

        public static function explicitFilesystemOperations(): iterable
        {
            foreach (['file factory', 'directory factory', 'asFile', 'asDirectory', 'exists', 'isFile', 'isDirectory'] as $operation) {
                yield $operation => [$operation];
            }
        }

        #[DataProvider('explicitFilesystemOperations')]
        public function testProbeDetectsExplicitFilesystemTransitions(string $operation): void
        {
            // Positive Kontrollen verhindern einen unbemerkt inaktiven Spy.
            $root = $this->fixture() . '/scope';
            $file = phore_uri($root . '/inside.txt');
            $directory = phore_uri($root);
            UriIsolationProbe::$calls = [];
            UriIsolationProbe::$armed = true;
            $caught = null;

            try {
                match ($operation) {
                    'file factory' => phore_file($root . '/inside.txt'),
                    'directory factory' => phore_dir($root),
                    'asFile' => $file->asFile(),
                    'asDirectory' => $directory->asDirectory(),
                    'exists' => $file->exists(),
                    'isFile' => $file->isFile(),
                    'isDirectory' => $directory->isDirectory(),
                };
            } catch (UriIsolationProbeViolation $exception) {
                $caught = $exception;
            } finally {
                UriIsolationProbe::$armed = false;
            }

            self::assertInstanceOf(UriIsolationProbeViolation::class, $caught);
            self::assertNotEmpty(UriIsolationProbe::$calls);
        }

        public function testDeferredSubpathRejectsSymlinkEscapeWithoutGlobalRoot(): void
        {
            $base = $this->fixture();
            self::assertTrue(\symlink($base . '/outside', $base . '/scope/external'));
            $uri = $this->withoutFilesystem(
                static fn () => phore_uri($base . '/scope')->withSubPath('external/secret.txt')->clean()
            );

            $this->expectException(PathOutOfBoundsException::class);
            $uri->asFile()->get_contents();
        }

        public function testEveryDeferredSubpathRetainsItsOwnLocalBoundary(): void
        {
            // Das Ziel bleibt im äußeren Root, verlässt aber die engere zweite Basis.
            $base = $this->fixture();
            self::assertTrue(\symlink($base . '/scope', $base . '/scope/nested/cross'));
            $uri = $this->withoutFilesystem(
                static fn () => phore_uri($base . '/scope')
                    ->withSubPath('nested')
                    ->withSubPath('cross/inside.txt')
            );

            $this->expectException(PathOutOfBoundsException::class);
            $uri->asFile()->get_contents();
        }

        public function testDeferredSubpathStillAllowsAnInternalSymlink(): void
        {
            $base = $this->fixture();
            self::assertTrue(\symlink($base . '/scope/target', $base . '/scope/internal'));
            $uri = $this->withoutFilesystem(
                static fn () => phore_uri($base . '/scope')->withSubPath('internal/inside.txt')->clean()
            );

            self::assertSame('internal target', $uri->asFile()->get_contents());
        }

        public static function rawSymlinkDerivations(): iterable
        {
            yield 'constructor with following' => [false, true];
            yield 'relative derivation with following' => [true, true];
            yield 'constructor without following' => [false, false];
            yield 'relative derivation without following' => [true, false];
        }

        #[DataProvider('rawSymlinkDerivations')]
        public function testCleaningCannotEraseRawSymlinkAndParentTraversal(
            bool $derive,
            bool $followSymlinks
        ): void {
            $base = $this->fixture();
            self::assertTrue(\symlink($base . '/outside', $base . '/scope/link'));
            $uri = $this->withoutFilesystem(function () use ($base, $derive, $followSymlinks): PhoreUri {
                $options = ['followSymlinks' => $followSymlinks];
                $uri = $derive
                    ? phore_uri($base . '/scope', $options)->withRelativePath('link/../inside.txt')
                    : phore_uri($base . '/scope/link/../inside.txt', $options);
                $clean = $uri->clean();
                self::assertSame($base . '/scope/inside.txt', $clean->getUri());

                return $clean;
            });

            $this->expectException(
                $followSymlinks ? FilesystemPolicyViolationException::class : SymlinkNotAllowedException::class
            );
            $uri->asFile()->get_contents();
        }

        public function testBoundRootIdentitySurvivesAPureUriRoundTrip(): void
        {
            // Ein gleichnamiger Ersatz-Root darf den bereits gebundenen Inode nicht ersetzen.
            $base = $this->fixture();
            $root = $base . '/scope';
            $bound = phore_file($root . '/inside.txt', ['rootDir' => $root]);
            self::assertSame('inside sentinel', $bound->get_contents());
            $uri = $this->withoutFilesystem(static fn () => phore_uri(phore_uri($bound, []))->clean());

            self::assertTrue(\rename($root, $base . '/original-scope'));
            self::assertTrue(\mkdir($root));
            self::assertSame(18, \file_put_contents($root . '/inside.txt', 'replacement secret'));
            \clearstatcache(true);

            $this->expectException(FilesystemPolicyViolationException::class);
            $uri->asFile()->get_contents();
        }

        public function testHardlinkRestrictionIsEnforcedAtTheDeferredTransition(): void
        {
            $base = $this->fixture();
            self::assertTrue(\link($base . '/scope/inside.txt', $base . '/outside/alias.txt'));
            $uri = $this->withoutFilesystem(static fn () => phore_uri(
                $base . '/scope/inside.txt',
                ['rootDir' => $base . '/scope', 'allowHardLinks' => false]
            )->clean());

            $this->expectException(FilesystemPolicyViolationException::class);
            $uri->asFile()->get_contents();
        }

        public static function policyRelaxations(): iterable
        {
            foreach (['root removed', 'root widened', 'symlinks enabled', 'hardlinks enabled', 'atomic disabled', 'default snapshot'] as $change) {
                yield $change => [$change];
            }
        }

        #[DataProvider('policyRelaxations')]
        public function testDeferredOptionsCannotRelaxInheritedRestrictions(string $change): void
        {
            $base = $this->fixture();
            $uri = $this->withoutFilesystem(function () use ($base, $change): PhoreUri {
                $source = phore_uri($base . '/scope/inside.txt', [
                    'rootDir' => $base . '/scope',
                    'followSymlinks' => false,
                    'allowHardLinks' => false,
                    'requireAtomicContainment' => $change === 'atomic disabled',
                ]);
                $options = match ($change) {
                    'root removed' => ['rootDir' => null],
                    'root widened' => ['rootDir' => $base],
                    'symlinks enabled' => ['followSymlinks' => true],
                    'hardlinks enabled' => ['allowHardLinks' => true],
                    'atomic disabled' => ['requireAtomicContainment' => false],
                    'default snapshot' => FilesystemOptions::fromAssoc([]),
                };

                return phore_uri($source, $options)->clean();
            });

            $this->expectException(FilesystemPolicyViolationException::class);
            $uri->asFile()->get_contents();
        }

        public function testExplicitBaseAnchorsARelativeUriInsideItsDeferredRoot(): void
        {
            $root = $this->fixture() . '/scope';
            $uri = $this->withoutFilesystem(static fn () => phore_uri(
                'inside.txt',
                ['rootDir' => $root]
            )->abs($root));

            self::assertSame($root . '/inside.txt', $uri->getUri());
            self::assertSame('inside sentinel', $uri->asFile()->get_contents());
        }

        #[DataProvider('rawSymlinkDerivations')]
        public function testExplicitBaseRetainsRawTraversalBeforeCleaning(
            bool $derive,
            bool $followSymlinks
        ): void {
            // Der gefährliche Link existiert erst unter der expliziten Basis.
            $base = $this->fixture();
            $root = $base . '/scope';
            self::assertTrue(\symlink($base . '/outside', $root . '/link'));
            $uri = $this->withoutFilesystem(function () use ($root, $derive, $followSymlinks): PhoreUri {
                $options = ['followSymlinks' => $followSymlinks];
                $relative = $derive
                    ? phore_uri('.', $options)->withRelativePath('link/../inside.txt')
                    : phore_uri('link/../inside.txt', $options);
                $absolute = $relative->clean()->abs($root);
                self::assertSame($root . '/inside.txt', $absolute->getUri());

                return $absolute;
            });

            $this->expectException(
                $followSymlinks ? FilesystemPolicyViolationException::class : SymlinkNotAllowedException::class
            );
            $uri->asFile()->get_contents();
        }

        public function testRenamedFileRetainsItsRootWithoutDependingOnTheOldName(): void
        {
            // Der alte Name darf nach rename() kein weiterhin geprüftes Zugriffsziel sein.
            $base = $this->fixture();
            $root = $base . '/scope';
            $file = phore_file($root . '/inside.txt', [
                'rootDir' => $root,
                'allowHardLinks' => false,
            ]);
            $options = $file->getFilesystemOptions()->toArray();
            self::assertSame($file, $file->rename($root . '/renamed.txt'));
            self::assertTrue(\symlink($base . '/outside/secret.txt', $root . '/inside.txt'));
            \clearstatcache(true);

            self::assertSame($root . '/renamed.txt', $file->getUri());
            self::assertSame($options, $file->getFilesystemOptions()->toArray());
            self::assertSame('inside sentinel', $file->get_contents());
            self::assertSame($file, $file->unlink());
            self::assertFileDoesNotExist($root . '/renamed.txt');
            self::assertTrue(\is_link($root . '/inside.txt'));
            self::assertSame('outside sentinel', \file_get_contents($base . '/outside/secret.txt'));

            $this->expectException(PathOutOfBoundsException::class);
            $file->withRelativePath('../outside/secret.txt')->asFile()->get_contents();
        }

        private function withoutFilesystem(callable $operation): mixed
        {
            self::assertFalse(UriIsolationProbe::$armed, 'Probe scopes must not overlap.');
            UriIsolationProbe::$calls = [];
            UriIsolationProbe::$armed = true;

            try {
                return $operation();
            } finally {
                UriIsolationProbe::$armed = false;
                self::assertSame([], UriIsolationProbe::$calls, 'Pure URI operations must make no filesystem probes.');
            }
        }

        private function fixture(): string
        {
            if ($this->fixtureDirectory !== null) {
                return $this->fixtureDirectory;
            }

            // Echte Ziele dienen als positive Kontrolle für die späteren Zugriffe.
            $base = \sys_get_temp_dir() . '/phore-uri-isolation-' . bin2hex(random_bytes(8));
            $this->fixtureDirectory = $base;
            foreach (['', '/scope', '/scope/nested', '/scope/target', '/outside'] as $suffix) {
                self::assertTrue(\mkdir($base . $suffix, 0700));
            }
            self::assertSame(15, \file_put_contents($base . '/scope/inside.txt', 'inside sentinel'));
            self::assertSame(15, \file_put_contents($base . '/scope/target/inside.txt', 'internal target'));
            self::assertSame(16, \file_put_contents($base . '/outside/secret.txt', 'outside sentinel'));

            return $base;
        }

        private function removeFixture(string $path): void
        {
            // Niemals Symlinks verfolgen, auch nicht bei einer fehlgeschlagenen Assertion.
            if (\is_link($path) || \is_file($path)) {
                self::assertTrue(\unlink($path));
                return;
            }
            if (!\is_dir($path)) {
                return;
            }
            $entries = \scandir($path);
            self::assertIsArray($entries);
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeFixture($path . '/' . $entry);
                }
            }
            self::assertTrue(\rmdir($path));
        }
    }
}

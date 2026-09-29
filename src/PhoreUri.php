<?php

namespace Phore\FileSystem;

use Phore\FileSystem\Exception\FileAccessException;
use Phore\FileSystem\Exception\FileNotFoundException;
use Phore\FileSystem\Exception\FilesystemException;
use Phore\FileSystem\Exception\FilesystemPolicyViolationException;
use Phore\FileSystem\Exception\PathOutOfBoundsException;

class PhoreUri
{
    /**
     * Display path returned by getUri() and __toString().
     */
    protected string $uri;

    /**
     * Absolute path used for filesystem operations.
     */
    protected string $accessPath;

    /**
     * Relative derivation history used by legacy walk/list APIs.
     *
     * @var string[]|null
     */
    protected ?array $relPath = null;

    protected FilesystemContext $filesystemContext;

    /**
     * Creates a URI and binds its filesystem restrictions.
     *
     * Security: A PhoreUri input inherits its complete context. options=null
     * and [] therefore never reset a root or another restriction. A string is
     * a new trusted entry point and receives the documented defaults.
     *
     * @param string|PhoreUri $uri Path or already bound Phore object.
     * @param string[]|null $__relPath Internal relative-path history.
     * @param array{
     *   rootDir?: string|null,
     *   followSymlinks?: bool,
     *   allowHardLinks?: bool,
     *   requireAtomicContainment?: bool
     * }|FilesystemOptions|null $options
     * @param FilesystemContext|null $__context Internal inherited context.
     * @param string|null $__accessPath Internal already resolved access path.
     * @throws FilesystemException
     * @see FilesystemOptions::fromAssoc()
     * @example phore_uri('/srv/site/docs', options: ['rootDir' => '/srv/site/docs']);
     */
    public function __construct(
        string|PhoreUri $uri,
        ?array $__relPath = null,
        array|FilesystemOptions|null $options = null,
        ?FilesystemContext $__context = null,
        ?string $__accessPath = null
    ) {
        if ($uri instanceof PhoreUri) {
            $this->uri = $uri->uri;
            $this->accessPath = $uri->accessPath;
            $this->relPath = $__relPath ?? $uri->relPath;
            $this->filesystemContext = $uri->filesystemContext->derive($this->accessPath, $options);
            $this->validate();

            return;
        }

        $this->uri = $uri;
        $this->relPath = $__relPath;

        if ($__context === null) {
            $this->filesystemContext = FilesystemContext::create($options);
            $this->accessPath = $__accessPath
                ?? $this->filesystemContext->resolveInputPath($uri);
        } else {
            $candidatePath = $__accessPath
                ?? $__context->resolveInputPath($uri);
            $this->filesystemContext = $__context->derive($candidatePath, $options);
            $this->accessPath = $candidatePath;
        }

        $this->validate();
    }

    public function validate(?string $optFileName = null): void
    {
        if (str_contains($this->uri, "\0")) {
            throw new FilesystemException("Null-byte character detected in uri '{$this->uri}'.");
        }
        if ($optFileName !== null && str_contains($optFileName, "\0")) {
            throw new FilesystemException("Null-byte character detected in uri '$optFileName'.");
        }
    }

    /**
     * Returns the inherited relative path or a path relative to an explicit base.
     *
     * The optional base changes representation only. It never changes rootDir,
     * the resolved access path or another filesystem restriction.
     *
     * @throws PathOutOfBoundsException
     * @see PhoreDirectory::listFiles()
     * @example $file->getRelPath($root);
     */
    public function getRelPath(?PhoreDirectory $base = null): ?string
    {
        if ($base === null) {
            return $this->relPath === null ? null : implode('/', $this->relPath);
        }

        $basePath = rtrim($base->accessPath, '/');
        $path = rtrim($this->accessPath, '/');

        if ($path === $basePath) {
            return '.';
        }
        if (!FilesystemContext::isWithin($path, $basePath)) {
            throw new PathOutOfBoundsException(
                "Path '{$this->uri}' is not below relative-path base '{$base->getUri()}'."
            );
        }

        return ltrim(substr($path, strlen($basePath)), '/');
    }

    /**
     * Returns the immutable options snapshot currently bound to this object.
     */
    public function getFilesystemOptions(): FilesystemOptions
    {
        return $this->filesystemContext->getOptions();
    }

    /**
     * Internal path authorization hook for streams and filesystem operations.
     *
     * @internal
     */
    public function getFilesystemPathForOperation(
        string $operation,
        bool $allowMissingLeaf = true
    ): string {
        return $this->filesystemContext->assertAccess(
            $this->accessPath,
            $operation,
            $allowMissingLeaf
        );
    }

    /**
     * @internal
     */
    public function assertRawResourceExportAllowed(): void
    {
        $this->filesystemContext->assertRawResourceExportAllowed();
    }

    /**
     * @internal
     */
    public function assertExternalProcessAllowed(string $operation): void
    {
        $this->filesystemContext->assertExternalProcessAllowed($operation);
    }

    /**
     * Remove duplicate slashes and dot segments while preserving restrictions.
     *
     * @see self::withSubPath()
     * @example phore_uri('/tmp//a/./b')->clean();
     */
    public function clean(): self
    {
        $display = self::normalizeDisplayPath($this->uri);
        $access = FilesystemContext::normalizeAbsolutePath($this->accessPath);

        return $this->spawn(static::class, $display, $access, $this->relPath);
    }

    public function getDirname(): self
    {
        return $this->spawn(
            PhoreUri::class,
            dirname($this->uri),
            dirname($this->accessPath),
            $this->parentRelPath()
        );
    }

    public function getBasename(string $suffix = ''): string
    {
        return basename($this->uri, $suffix);
    }

    public function getExtension(): string
    {
        return pathinfo($this->uri, PATHINFO_EXTENSION);
    }

    public function getFilename(): string
    {
        return pathinfo($this->uri, PATHINFO_FILENAME);
    }

    public function withDirName(): PhoreDirectory
    {
        return $this->spawn(
            PhoreDirectory::class,
            dirname($this->uri),
            dirname($this->accessPath),
            $this->parentRelPath()
        );
    }

    public function withSubPath(string $subpath): PhoreUri
    {
        $relative = $this->assertRelativePath($subpath);
        $displayBase = $this instanceof PhoreFile ? dirname($this->uri) : $this->uri;
        $accessBase = $this instanceof PhoreFile ? dirname($this->accessPath) : $this->accessPath;
        $access = $this->filesystemContext->resolveRelative($accessBase, $subpath);
        $display = self::joinDisplayPath($displayBase, $relative);

        $relPath = $this->relPath ?? [];
        foreach (explode('/', $relative) as $part) {
            if ($part !== '') {
                $relPath[] = $part;
            }
        }

        return $this->spawn(PhoreUri::class, $display, $access, $relPath);
    }

    public function withRelativePath(string $relpath): PhoreUri
    {
        if ($relpath === '') {
            throw new PathOutOfBoundsException('Relative path must not be empty.');
        }

        $displayBase = $this instanceof PhoreFile ? dirname($this->uri) : $this->uri;
        $accessBase = $this instanceof PhoreFile ? dirname($this->accessPath) : $this->accessPath;
        $access = $this->filesystemContext->resolveRelative($accessBase, $relpath);
        $display = self::normalizeDisplayPath(rtrim($displayBase, '/') . '/' . $relpath);

        return $this->spawn(PhoreUri::class, $display, $access, null);
    }

    /**
     * Validates a local relative path and returns its normalized representation.
     *
     * This assertion is a path-shape check. The bound filesystem context still
     * performs root and link authorization when the path is derived or used.
     *
     * @throws PathOutOfBoundsException
     * @see self::withSubPath()
     * @example $root->assertRelativePath('pages/index.md');
     */
    public function assertRelativePath(string $path): string
    {
        if (
            $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $path)
        ) {
            throw new PathOutOfBoundsException("Invalid relative path '$path'.");
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    throw new PathOutOfBoundsException("Relative path escapes its start directory: '$path'.");
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        if ($parts === []) {
            throw new PathOutOfBoundsException("Relative path resolves to an empty path: '$path'.");
        }

        return implode('/', $parts);
    }

    public function fnmatch($patterns, int $flags = 0): bool
    {
        if (!is_array($patterns)) {
            $patterns = [$patterns];
        }
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, (string) $this, $flags)) {
                return true;
            }
        }

        return false;
    }

    public function isDirectory(): bool
    {
        $path = $this->filesystemContext->assertAccess($this->accessPath, 'isDirectory', true);

        return is_dir($path);
    }

    public function isFile(): bool
    {
        $path = $this->filesystemContext->assertAccess($this->accessPath, 'isFile', true);

        return is_file($path);
    }

    public function exists(): bool
    {
        $path = $this->filesystemContext->assertAccess($this->accessPath, 'exists', true);

        return file_exists($path) || is_link($path);
    }

    public function isSubpathOf($path): bool
    {
        $candidate = self::normalizeDisplayPath((string) $this);
        $root = self::normalizeDisplayPath((string) $path);

        return $candidate === $root || str_starts_with($candidate, rtrim($root, '/') . '/');
    }

    public function assertDirectory(bool $createIfNotExisting = false): PhoreDirectory
    {
        $directory = $this->asDirectory();

        if ($createIfNotExisting && !$directory->exists()) {
            $directory->mkdir();
        }

        $path = $directory->getFilesystemPathForOperation('assertDirectory', false);
        if (!is_dir($path)) {
            throw new FilesystemException("Uri '{$this->uri}' is not a valid directory.");
        }

        return $directory;
    }

    public function assertFile(bool $createIfNotExisting = false): PhoreFile
    {
        $file = $this->asFile();

        if ($file->isDirectory()) {
            throw new FilesystemException("Uri '{$this->uri}' is a directory, not a file.");
        }

        if ($createIfNotExisting && !$file->exists()) {
            $file->getDirname()->assertDirectory(true);
            $file->touch();
        }

        $path = $file->getFilesystemPathForOperation('assertFile', false);
        if (!is_file($path)) {
            throw new FilesystemException("Uri '{$this->uri}' is not a valid file.");
        }

        return $file;
    }

    public function assertReadable(): self
    {
        $path = $this->getFilesystemPathForOperation('assertReadable', false);
        if (!is_readable($path)) {
            throw new FileAccessException("Uri '{$this->uri}' is not readable.");
        }

        return $this;
    }

    public function assertWritable(): self
    {
        $path = $this->getFilesystemPathForOperation('assertWritable', false);
        if (!is_writable($path)) {
            throw new FileAccessException("Uri '{$this->uri}' is not writable.");
        }

        return $this;
    }

    /**
     * Returns a same-type object with symlink following disabled.
     *
     * @throws FilesystemPolicyViolationException
     * @see FilesystemOptions
     * @example $safe = $file->assertNoSymlinks();
     */
    public function assertNoSymlinks(): static
    {
        /** @var static $result */
        $result = $this->spawn(
            static::class,
            $this->uri,
            $this->accessPath,
            $this->relPath,
            ['followSymlinks' => false]
        );

        return $result;
    }

    /**
     * Checks a prospective file target without creating it.
     *
     * @throws FilesystemException
     * @see PhoreFile::set_contents()
     * @example $root->withSubPath('output/file.txt')->assertFileTarget();
     */
    public function assertFileTarget(): PhoreFile
    {
        $file = $this->asFile();
        $path = $file->getFilesystemPathForOperation('assertFileTarget', true);

        if (is_dir($path)) {
            throw new FilesystemException("File target '{$file->getUri()}' is a directory.");
        }
        if (file_exists($path) && !is_writable($path)) {
            throw new FileAccessException("File target '{$file->getUri()}' is not writable.");
        }

        $parent = dirname($path);
        while (!file_exists($parent)) {
            $next = dirname($parent);
            if ($next === $parent) {
                break;
            }
            $parent = $next;
        }

        $file->filesystemContext->assertAccess($parent, 'assertFileTarget parent', false);
        if (!is_dir($parent) || !is_writable($parent)) {
            throw new FileAccessException(
                "Parent directory '$parent' for file target '{$file->getUri()}' is not writable."
            );
        }

        return $file;
    }

    public function getUri(): string
    {
        $this->validate();

        return $this->uri;
    }

    public function __toString()
    {
        return $this->uri;
    }

    public function asFile(): PhoreFile
    {
        return $this->spawn(PhoreFile::class, $this->uri, $this->accessPath, $this->relPath);
    }

    public function join(...$elements): PhoreUri
    {
        $path = $this;
        foreach ($elements as $element) {
            $element = ltrim((string) $element, '/');
            $path = $path->withSubPath($element);
        }

        return $path;
    }

    public function join_secure(...$elements): PhoreUri
    {
        $path = $this;
        foreach ($elements as $element) {
            $element = (string) $element;
            if ($element === '.' || $element === '..') {
                throw new \InvalidArgumentException("Path security violation: path must not contain '.' or '..'.");
            }

            $element = urlencode($element);
            if ($element === '') {
                throw new \InvalidArgumentException('Path must not contain an empty string element.');
            }
            $path = $path->withSubPath($element);
        }

        return $path;
    }

    public function abs(?string $cwd = null): PhoreUri
    {
        if (str_starts_with($this->uri, '/')) {
            return $this->spawn(PhoreUri::class, $this->uri, $this->accessPath, $this->relPath);
        }

        $cwd ??= getcwd();
        if ($cwd === false || $cwd === null) {
            throw new FilesystemException('Cannot resolve absolute path without a cwd.');
        }

        $display = rtrim($cwd, '/') . '/' . $this->uri;
        $access = $this->filesystemContext->resolveInputPath($display);

        return $this->spawn(PhoreUri::class, $display, $access, $this->relPath);
    }

    public function rel(string $rootPath): PhoreUri
    {
        if (!str_starts_with($this->uri, '/')) {
            return $this->spawn(PhoreUri::class, $this->uri, $this->accessPath, $this->relPath);
        }

        $rootPath = rtrim(self::normalizeDisplayPath($rootPath), '/');
        $current = self::normalizeDisplayPath($this->uri);
        if ($current !== $rootPath && !str_starts_with($current, $rootPath . '/')) {
            throw new \InvalidArgumentException(
                "Path '{$this->uri}' is not a subpath of '$rootPath'."
            );
        }

        $display = ltrim(substr($current, strlen($rootPath)), '/');
        if ($display === '') {
            $display = '.';
        }

        return $this->spawn(PhoreUri::class, $display, $this->accessPath, $this->relPath);
    }

    public function withFileName(string $filename, string $fileExtension = ''): PhoreFile
    {
        if (
            $filename === ''
            || str_contains($filename, '/')
            || str_contains($filename, '\\')
            || str_contains($filename, "\0")
        ) {
            throw new \InvalidArgumentException("Invalid filename '$filename'.");
        }
        if ($fileExtension !== '' && !ctype_alnum($fileExtension)) {
            throw new \InvalidArgumentException(
                "File extension '$fileExtension' must not contain special chars."
            );
        }

        $name = $filename . ($fileExtension === '' ? '' : '.' . $fileExtension);
        $child = $this->withSubPath($name);

        return $child->asFile();
    }

    public function withFileExtension(
        string $fileExtension,
        bool $replaceExistingExtension = false,
        bool $strictChecks = true
    ): PhoreFile {
        if (
            str_contains($fileExtension, '/')
            || str_contains($fileExtension, '\\')
            || str_contains($fileExtension, "\0")
        ) {
            throw new \InvalidArgumentException(
                "File extension must not contain path separators or NUL."
            );
        }

        if ($strictChecks && $fileExtension !== '' && !ctype_alnum($fileExtension)) {
            throw new \InvalidArgumentException(
                "File extension '$fileExtension' must not contain special chars."
            );
        }

        $suffix = $fileExtension === '' ? '' : '.' . $fileExtension;
        $display = $this->uri;
        $access = $this->accessPath;

        if ($replaceExistingExtension) {
            $display = preg_replace('/\.[a-z0-9]+$/i', '', $display) ?? $display;
            $access = preg_replace('/\.[a-z0-9]+$/i', '', $access) ?? $access;
        }

        $relPath = $this->relPath;
        if ($relPath !== null && $relPath !== []) {
            $last = array_pop($relPath);
            if ($replaceExistingExtension) {
                $last = preg_replace('/\.[a-z0-9]+$/i', '', $last) ?? $last;
            }
            $relPath[] = $last . $suffix;
        }

        return $this->spawn(
            PhoreFile::class,
            $display . $suffix,
            $access . $suffix,
            $relPath
        );
    }

    public function withParentDir(): PhoreDirectory
    {
        $display = dirname($this->uri);
        if ($display === '.') {
            $display = '/';
        }

        return $this->spawn(
            PhoreDirectory::class,
            $display,
            dirname($this->accessPath),
            $this->parentRelPath()
        );
    }

    public function asDirectory(): PhoreDirectory
    {
        return $this->spawn(PhoreDirectory::class, $this->uri, $this->accessPath, $this->relPath);
    }

    /**
     * @template T of PhoreUri
     * @param class-string<T> $class
     * @param string[]|null $relPath
     * @param array{
     *   rootDir?: string|null,
     *   followSymlinks?: bool,
     *   allowHardLinks?: bool,
     *   requireAtomicContainment?: bool
     * }|FilesystemOptions|null $options
     * @return T
     */
    protected function spawn(
        string $class,
        string $displayPath,
        string $accessPath,
        ?array $relPath,
        array|FilesystemOptions|null $options = null
    ): PhoreUri {
        return new $class(
            $displayPath,
            $relPath,
            $options,
            $this->filesystemContext,
            $accessPath
        );
    }

    /**
     * @param string|PhoreUri $target
     */
    protected function resolveTargetFile(string|PhoreUri $target): PhoreFile
    {
        if ($target instanceof PhoreUri) {
            return $target->asFile();
        }

        return $this->spawn(
            PhoreFile::class,
            $target,
            $this->filesystemContext->resolveInputPath($target),
            null
        );
    }

    protected function adoptPath(PhoreUri $target): void
    {
        $this->uri = $target->uri;
        $this->accessPath = $target->accessPath;
        $this->relPath = $target->relPath;
        $this->filesystemContext = $target->filesystemContext;
    }

    /**
     * @return string[]|null
     */
    private function parentRelPath(): ?array
    {
        if ($this->relPath === null) {
            return null;
        }

        $parts = $this->relPath;
        array_pop($parts);

        return $parts;
    }

    private static function normalizeDisplayPath(string $path): string
    {
        $absolute = str_starts_with($path, '/');
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        $normalized = implode('/', $parts);

        return $absolute ? '/' . $normalized : $normalized;
    }

    private static function joinDisplayPath(string $base, string $relative): string
    {
        $base = rtrim($base, '/');

        return ($base === '' ? '' : $base . '/') . $relative;
    }
}

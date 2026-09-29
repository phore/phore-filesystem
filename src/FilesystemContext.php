<?php

namespace Phore\FileSystem;

use Phore\FileSystem\Exception\FileAccessException;
use Phore\FileSystem\Exception\FileNotFoundException;
use Phore\FileSystem\Exception\FilesystemPolicyViolationException;
use Phore\FileSystem\Exception\PathOutOfBoundsException;
use Phore\FileSystem\Exception\SymlinkNotAllowedException;
use Phore\FileSystem\Exception\UnsupportedFilesystemPolicyException;

/**
 * Internal immutable authorization context carried by every PhoreUri.
 *
 * @internal
 */
final class FilesystemContext
{
    private ?string $rootRealPath = null;
    private ?int $rootDevice = null;
    private ?int $rootInode = null;

    private function __construct(private readonly FilesystemOptions $options)
    {
        if ($this->options->rootDir !== null) {
            $this->bindRoot();
        }
    }

    /**
     * Creates the context for a new trusted entry point.
     *
     * @param array{
     *   rootDir?: string|null,
     *   followSymlinks?: bool,
     *   allowHardLinks?: bool,
     *   requireAtomicContainment?: bool
     * }|FilesystemOptions|null $options
     */
    public static function create(array|FilesystemOptions|null $options): self
    {
        return new self(FilesystemOptions::from($options));
    }

    public function getOptions(): FilesystemOptions
    {
        return $this->options;
    }

    /**
     * Resolves and authorizes a path supplied at an API entry point.
     */
    public function resolveInputPath(string $path): string
    {
        $this->assertLocalPathSyntax($path);
        $rawAbsolute = $this->makeAbsolute($path);
        $this->assertRawResolutionIsSafe($rawAbsolute);
        $absolute = self::normalizeAbsolutePath($rawAbsolute);
        $this->assertPathPolicy($absolute, true);

        return $absolute;
    }

    /**
     * Resolves a relative child path without weakening the current context.
     */
    public function resolveRelative(string $basePath, string $relativePath): string
    {
        if ($relativePath === '' || str_starts_with($relativePath, '/')) {
            throw new PathOutOfBoundsException("Relative path must be non-empty and must not be absolute: '$relativePath'.");
        }

        $this->assertLocalPathSyntax($relativePath);
        $rawAbsolute = rtrim(self::normalizeAbsolutePath($basePath), '/') . '/' . $relativePath;
        $this->assertRawResolutionIsSafe($rawAbsolute);
        $absolute = self::normalizeAbsolutePath($rawAbsolute);
        $this->assertPathPolicy($absolute, true);

        return $absolute;
    }

    /**
     * Derives a context from an already authorized object.
     *
     * Missing options and an empty array inherit everything. A partial array
     * patches only explicitly present keys. A FilesystemOptions object is a
     * complete snapshot and is therefore checked in full for monotonicity.
     *
     * @param array{
     *   rootDir?: string|null,
     *   followSymlinks?: bool,
     *   allowHardLinks?: bool,
     *   requireAtomicContainment?: bool
     * }|FilesystemOptions|null $options
     */
    public function derive(string $path, array|FilesystemOptions|null $options): self
    {
        if ($options === null || $options === []) {
            $this->assertPathPolicy($path, true);

            return $this;
        }

        $candidateOptions = is_array($options)
            ? FilesystemOptions::patch($this->options, $options)
            : $options;
        $candidate = new self($candidateOptions);

        $this->assertMonotonic($candidate);
        $candidate->assertPathPolicy($path, true);

        return $candidate;
    }

    /**
     * Authorizes a real filesystem operation.
     *
     * Atomic containment intentionally fails closed until a backend can bind
     * path resolution and the actual operation in one supported primitive.
     */
    public function assertAccess(
        string $path,
        string $operation,
        bool $allowMissingLeaf = true
    ): string {
        if ($this->options->requireAtomicContainment) {
            throw new UnsupportedFilesystemPolicyException(
                "Filesystem operation '$operation' requires atomic containment, but no atomic backend is available."
            );
        }

        $absolute = self::normalizeAbsolutePath($path);
        $this->assertPathPolicy($absolute, $allowMissingLeaf);

        return $absolute;
    }

    /**
     * Raw stream resources leave the object-level policy boundary.
     */
    public function assertRawResourceExportAllowed(): void
    {
        if ($this->options->rootDir !== null || $this->options->requireAtomicContainment) {
            throw new UnsupportedFilesystemPolicyException(
                "Raw stream export is not supported for root-bound or atomically contained filesystem access."
            );
        }
    }

    public function assertExternalProcessAllowed(string $operation): void
    {
        if ($this->options->rootDir !== null || $this->options->requireAtomicContainment) {
            throw new UnsupportedFilesystemPolicyException(
                "External operation '$operation' is not supported for root-bound filesystem access."
            );
        }
    }

    /**
     * Normalize a local POSIX path without touching the filesystem.
     */
    public static function normalizeAbsolutePath(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new FilesystemPolicyViolationException("Cannot resolve path without a current working directory.");
            }
            $path = rtrim($cwd, '/') . '/' . $path;
        }

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

        return '/' . implode('/', $parts);
    }

    public static function isWithin(string $path, string $root): bool
    {
        $path = rtrim(self::normalizeAbsolutePath($path), '/');
        $root = rtrim(self::normalizeAbsolutePath($root), '/');

        if ($root === '') {
            $root = '/';
        }
        if ($path === '') {
            $path = '/';
        }

        return $root === '/' || $path === $root || str_starts_with($path, $root . '/');
    }

    private function bindRoot(): void
    {
        $root = $this->options->rootDir;
        if ($root === null) {
            return;
        }

        if (!$this->options->followSymlinks) {
            $this->assertNoSymlinkComponents($root, false);
        }

        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new FilesystemPolicyViolationException(
                "Filesystem root '$root' does not exist or is not a directory."
            );
        }

        $stat = @stat($real);
        if ($stat === false) {
            throw new FileAccessException("Cannot stat filesystem root '$root'.");
        }

        $this->rootRealPath = self::normalizeAbsolutePath($real);
        $this->rootDevice = (int) $stat['dev'];
        $this->rootInode = (int) $stat['ino'];
    }

    private function assertRootIdentity(): void
    {
        if ($this->options->rootDir === null) {
            return;
        }

        $real = realpath($this->options->rootDir);
        if ($real === false) {
            throw new FilesystemPolicyViolationException(
                "Filesystem root '{$this->options->rootDir}' can no longer be resolved."
            );
        }

        $stat = @stat($real);
        if ($stat === false) {
            throw new FileAccessException("Cannot stat filesystem root '{$this->options->rootDir}'.");
        }

        if (
            self::normalizeAbsolutePath($real) !== $this->rootRealPath
            || (int) $stat['dev'] !== $this->rootDevice
            || (int) $stat['ino'] !== $this->rootInode
        ) {
            throw new FilesystemPolicyViolationException(
                "Filesystem root '{$this->options->rootDir}' changed after it was bound."
            );
        }
    }

    private function assertMonotonic(self $candidate): void
    {
        if (!$this->options->followSymlinks && $candidate->options->followSymlinks) {
            throw new FilesystemPolicyViolationException(
                "followSymlinks cannot be enabled on an object that inherited followSymlinks=false."
            );
        }

        if (!$this->options->allowHardLinks && $candidate->options->allowHardLinks) {
            throw new FilesystemPolicyViolationException(
                "allowHardLinks cannot be enabled on an object that inherited allowHardLinks=false."
            );
        }

        if ($this->options->requireAtomicContainment && !$candidate->options->requireAtomicContainment) {
            throw new FilesystemPolicyViolationException(
                "requireAtomicContainment cannot be disabled on an object that inherited it."
            );
        }

        if ($this->rootRealPath !== null) {
            if ($candidate->rootRealPath === null) {
                throw new FilesystemPolicyViolationException(
                    "rootDir cannot be removed from an already root-bound filesystem object."
                );
            }

            if (!self::isWithin($candidate->rootRealPath, $this->rootRealPath)) {
                throw new FilesystemPolicyViolationException(
                    "rootDir can only remain unchanged or be narrowed to a verified child directory."
                );
            }
        }
    }

    private function assertPathPolicy(string $absolute, bool $allowMissingLeaf): void
    {
        $absolute = self::normalizeAbsolutePath($absolute);
        $this->assertRootIdentity();

        if (
            $this->options->rootDir !== null
            && !self::isWithin($absolute, $this->options->rootDir)
        ) {
            throw new PathOutOfBoundsException(
                "Path '$absolute' is outside filesystem root '{$this->options->rootDir}'."
            );
        }

        if ($this->options->followSymlinks) {
            $this->assertResolvedPathWithinRoot($absolute, $allowMissingLeaf);
        } else {
            $this->assertNoSymlinkComponents($absolute, $allowMissingLeaf);
        }

        $exists = file_exists($absolute) || is_link($absolute);
        if (!$exists) {
            if (!$allowMissingLeaf) {
                throw new FileNotFoundException("Path '$absolute' does not exist.");
            }

            return;
        }

        $stat = $this->options->followSymlinks ? @stat($absolute) : @lstat($absolute);
        if ($stat === false) {
            throw new FileAccessException("Cannot stat path '$absolute'.");
        }

        $type = $stat['mode'] & 0170000;
        if ($type !== 0040000 && $type !== 0100000) {
            throw new UnsupportedFilesystemPolicyException(
                "Unsupported filesystem entry type at '$absolute'. Only regular files and directories are allowed."
            );
        }

        if (
            !$this->options->allowHardLinks
            && $type === 0100000
            && (int) ($stat['nlink'] ?? 1) > 1
        ) {
            throw new FilesystemPolicyViolationException(
                "Hard-linked file '$absolute' is not allowed by the current filesystem policy."
            );
        }
    }

    private function assertResolvedPathWithinRoot(string $absolute, bool $allowMissingLeaf): void
    {
        if ($this->rootRealPath === null) {
            if (is_link($absolute) && realpath($absolute) === false) {
                throw new FilesystemPolicyViolationException("Broken symlink '$absolute' cannot be resolved.");
            }

            return;
        }

        $probe = $absolute;
        while (!file_exists($probe) && !is_link($probe)) {
            if ($probe === '/' || dirname($probe) === $probe) {
                break;
            }
            $probe = dirname($probe);
        }

        if (!file_exists($probe) && !is_link($probe)) {
            if ($allowMissingLeaf) {
                throw new FileAccessException(
                    "Cannot establish a filesystem parent for '$absolute'."
                );
            }
            throw new FileNotFoundException("Path '$absolute' does not exist.");
        }

        $real = realpath($probe);
        if ($real === false) {
            throw new FilesystemPolicyViolationException(
                "Path '$probe' contains a broken or unresolvable symlink."
            );
        }

        if (!self::isWithin($real, $this->rootRealPath)) {
            throw new PathOutOfBoundsException(
                "Resolved path '$real' escapes filesystem root '{$this->options->rootDir}'."
            );
        }
    }

    private function assertNoSymlinkComponents(string $absolute, bool $allowMissingLeaf): void
    {
        $parts = array_values(array_filter(explode('/', $absolute), static fn(string $part): bool => $part !== ''));
        $current = '';

        foreach ($parts as $index => $part) {
            $current .= '/' . $part;
            $stat = @lstat($current);

            if ($stat === false) {
                $parent = dirname($current);
                if (!is_dir($parent) || !is_readable($parent)) {
                    throw new FileAccessException(
                        "Cannot safely inspect filesystem path '$current'."
                    );
                }

                if (!$allowMissingLeaf) {
                    throw new FileNotFoundException("Path '$current' does not exist.");
                }

                // A prospective create path may have multiple missing descendants.
                // The last existing parent has already been checked and no
                // unchecked existing symlink may occur below a missing component.
                return;
            }

            if (($stat['mode'] & 0170000) === 0120000) {
                throw new SymlinkNotAllowedException(
                    "Symlink '$current' is not allowed by the current filesystem policy."
                );
            }
        }
    }

    private function assertRawResolutionIsSafe(string $rawAbsolute): void
    {
        if (!$this->options->followSymlinks) {
            $this->assertRawPathContainsNoSymlink($rawAbsolute);

            return;
        }

        if (!str_contains('/' . trim($rawAbsolute, '/') . '/', '/../')) {
            return;
        }

        if ($this->rawPathContainsSymlink($rawAbsolute)) {
            throw new FilesystemPolicyViolationException(
                "Paths combining symlinks with '..' are not safely resolvable by this backend."
            );
        }
    }

    private function assertRawPathContainsNoSymlink(string $rawAbsolute): void
    {
        $parts = explode('/', $rawAbsolute);
        $stack = [];
        $missing = false;

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if ($missing) {
                    throw new FilesystemPolicyViolationException(
                        "Cannot normalize '..' across a missing filesystem component."
                    );
                }
                array_pop($stack);
                continue;
            }

            $stack[] = $part;
            if ($missing) {
                continue;
            }

            $current = '/' . implode('/', $stack);
            $stat = @lstat($current);
            if ($stat === false) {
                $parent = dirname($current);
                if (!is_dir($parent) || !is_readable($parent)) {
                    throw new FileAccessException(
                        "Cannot safely inspect filesystem path '$current'."
                    );
                }
                $missing = true;
                continue;
            }

            if (($stat['mode'] & 0170000) === 0120000) {
                throw new SymlinkNotAllowedException(
                    "Symlink '$current' is not allowed by the current filesystem policy."
                );
            }
        }
    }

    private function rawPathContainsSymlink(string $rawAbsolute): bool
    {
        $parts = explode('/', $rawAbsolute);
        $stack = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($stack);
                continue;
            }

            $stack[] = $part;
            $current = '/' . implode('/', $stack);
            $stat = @lstat($current);
            if ($stat === false) {
                return false;
            }
            if (($stat['mode'] & 0170000) === 0120000) {
                return true;
            }
        }

        return false;
    }

    private function assertLocalPathSyntax(string $path): void
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new FilesystemPolicyViolationException("Filesystem path must be non-empty and must not contain NUL.");
        }
        if (str_contains($path, '\\')) {
            throw new FilesystemPolicyViolationException(
                "Backslash paths are not supported by the current filesystem backend."
            );
        }
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//', $path)) {
            throw new UnsupportedFilesystemPolicyException(
                "Stream and URI schemes are not supported by the local filesystem policy."
            );
        }
    }

    private function makeAbsolute(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        $cwd = getcwd();
        if ($cwd === false) {
            throw new FilesystemPolicyViolationException("Cannot resolve relative path without a cwd.");
        }

        return rtrim($cwd, '/') . '/' . $path;
    }
}

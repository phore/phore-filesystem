<?php

namespace Phore\FileSystem;

use Phore\FileSystem\Exception\InvalidFilesystemOptionsException;

/**
 * Immutable filesystem security configuration.
 *
 * Use fromAssoc() when configuration is supplied as an associative array.
 * Missing values receive the defaults only for a new filesystem entry. When
 * options are applied to an already bound PhoreUri, FilesystemContext merges
 * partial arrays with the inherited restrictions instead.
 */
final class FilesystemOptions
{
    public readonly ?string $rootDir;
    public readonly bool $followSymlinks;
    public readonly bool $allowHardLinks;
    public readonly bool $requireAtomicContainment;

    public function __construct(
        ?string $rootDir = null,
        bool $followSymlinks = false,
        bool $allowHardLinks = true,
        bool $requireAtomicContainment = false
    ) {
        $this->rootDir = $rootDir === null ? null : self::normalizeRootDir($rootDir);
        $this->followSymlinks = $followSymlinks;
        $this->allowHardLinks = $allowHardLinks;
        $this->requireAtomicContainment = $requireAtomicContainment;

        if ($this->requireAtomicContainment && $this->rootDir === null) {
            throw new InvalidFilesystemOptionsException(
                "requireAtomicContainment=true requires a non-null rootDir."
            );
        }
    }

    /**
     * Validates an associative configuration and returns a complete snapshot.
     *
     * Unknown keys and non-exact value types are rejected; values are never
     * silently cast. The accepted keys are:
     * - rootDir: string|null, default null (no root boundary)
     * - followSymlinks: bool, default false
     * - allowHardLinks: bool, default true
     * - requireAtomicContainment: bool, default false
     *
     * @param array{
     *   rootDir?: string|null,
     *   followSymlinks?: bool,
     *   allowHardLinks?: bool,
     *   requireAtomicContainment?: bool
     * } $options
     * @throws InvalidFilesystemOptionsException
     * @see FilesystemContext
     * @example FilesystemOptions::fromAssoc(['rootDir' => '/srv/site/docs']);
     */
    public static function fromAssoc(array $options): self
    {
        self::validateAssoc($options);

        return new self(
            rootDir: $options['rootDir'] ?? null,
            followSymlinks: $options['followSymlinks'] ?? false,
            allowHardLinks: $options['allowHardLinks'] ?? true,
            requireAtomicContainment: $options['requireAtomicContainment'] ?? false
        );
    }

    /**
     * Normalizes the public union accepted by filesystem factories.
     *
     * @param array{
     *   rootDir?: string|null,
     *   followSymlinks?: bool,
     *   allowHardLinks?: bool,
     *   requireAtomicContainment?: bool
     * }|FilesystemOptions|null $options
     * @throws InvalidFilesystemOptionsException
     * @see self::fromAssoc()
     * @example FilesystemOptions::from(['followSymlinks' => true]);
     */
    public static function from(array|self|null $options): self
    {
        if ($options instanceof self) {
            return $options;
        }

        return self::fromAssoc($options ?? []);
    }

    /**
     * Applies an associative patch to a complete inherited snapshot.
     *
     * This method validates shape and types only. FilesystemContext separately
     * verifies that the result does not relax an inherited restriction.
     *
     * @param array{
     *   rootDir?: string|null,
     *   followSymlinks?: bool,
     *   allowHardLinks?: bool,
     *   requireAtomicContainment?: bool
     * } $patch
     * @throws InvalidFilesystemOptionsException
     * @see FilesystemContext::derive()
     * @example FilesystemOptions::patch($base, ['followSymlinks' => false]);
     */
    public static function patch(self $base, array $patch): self
    {
        self::validateAssoc($patch);

        return new self(
            rootDir: array_key_exists('rootDir', $patch) ? $patch['rootDir'] : $base->rootDir,
            followSymlinks: array_key_exists('followSymlinks', $patch)
                ? $patch['followSymlinks']
                : $base->followSymlinks,
            allowHardLinks: array_key_exists('allowHardLinks', $patch)
                ? $patch['allowHardLinks']
                : $base->allowHardLinks,
            requireAtomicContainment: array_key_exists('requireAtomicContainment', $patch)
                ? $patch['requireAtomicContainment']
                : $base->requireAtomicContainment
        );
    }

    /**
     * @return array{
     *   rootDir: string|null,
     *   followSymlinks: bool,
     *   allowHardLinks: bool,
     *   requireAtomicContainment: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'rootDir' => $this->rootDir,
            'followSymlinks' => $this->followSymlinks,
            'allowHardLinks' => $this->allowHardLinks,
            'requireAtomicContainment' => $this->requireAtomicContainment,
        ];
    }

    private static function validateAssoc(array $options): void
    {
        $allowed = [
            'rootDir',
            'followSymlinks',
            'allowHardLinks',
            'requireAtomicContainment',
        ];

        foreach (array_keys($options) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $label = is_scalar($key) ? (string) $key : get_debug_type($key);
                throw new InvalidFilesystemOptionsException("Unknown filesystem option '$label'.");
            }
        }

        if (
            array_key_exists('rootDir', $options)
            && $options['rootDir'] !== null
            && !is_string($options['rootDir'])
        ) {
            throw new InvalidFilesystemOptionsException("rootDir must be string|null.");
        }

        foreach (['followSymlinks', 'allowHardLinks', 'requireAtomicContainment'] as $key) {
            if (array_key_exists($key, $options) && !is_bool($options[$key])) {
                throw new InvalidFilesystemOptionsException("$key must be bool.");
            }
        }
    }

    private static function normalizeRootDir(string $rootDir): string
    {
        if ($rootDir === '' || str_contains($rootDir, "\0")) {
            throw new InvalidFilesystemOptionsException("rootDir must be a non-empty local path.");
        }
        if (str_contains($rootDir, '\\')) {
            throw new InvalidFilesystemOptionsException("rootDir must use forward slashes.");
        }
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//', $rootDir)) {
            throw new InvalidFilesystemOptionsException("rootDir must not use a stream or URI scheme.");
        }

        if (!str_starts_with($rootDir, '/')) {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new InvalidFilesystemOptionsException("Cannot resolve relative rootDir without a cwd.");
            }
            $rootDir = rtrim($cwd, '/') . '/' . $rootDir;
        }

        return self::normalizeAbsolutePath($rootDir);
    }

    private static function normalizeAbsolutePath(string $path): string
    {
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
}

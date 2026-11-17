<?php

declare(strict_types=1);

namespace Phore\FileSystem;

/**
 * Deferred filesystem provenance for an independently displayed URI path.
 *
 * Building an intent never binds a filesystem root or resolves a path. Raw
 * inputs and their derivation order survive lexical URI normalization. Only
 * resolve() enters the existing FilesystemContext security boundary.
 *
 * Options retain their existing syntax validation, including getcwd() when
 * FilesystemOptions receives an explicitly relative rootDir.
 *
 * @internal
 */
final class FilesystemPath
{
    /**
     * @param \Closure(?array, ?string): array{FilesystemContext, string} $resolver
     */
    private function __construct(
        private readonly FilesystemOptions $options,
        private readonly \Closure $resolver,
        private readonly ?self $source = null,
        private readonly ?bool $relativeEntry = null,
        private readonly ?string $explicitBase = null
    ) {
    }

    /**
     * Defers a fresh entry point without interpreting its raw path.
     *
     * The first resolution anchors relative paths and binds root identity.
     * Later resolutions retain that binding and revalidate its policy.
     *
     * @see FilesystemContext::resolveInputPath()
     */
    public static function entry(
        string $raw,
        array|FilesystemOptions|null $options = null
    ): self {
        $snapshot = FilesystemOptions::from($options);
        $context = null;
        $access = [];

        // Den gebundenen Context auch nach einer fehlgeschlagenen Pfadprüfung behalten.
        $resolver = static function (?array $source, ?string $base) use ($raw, $snapshot, &$context, &$access): array {
            $context ??= FilesystemContext::create($snapshot);
            $key = $base ?? '';
            $access[$key] ??= $context->resolveInputPath(self::atBase($raw, $base));

            return [$context, $access[$key]];
        };

        return new self($snapshot, $resolver, relativeEntry: !str_starts_with($raw, '/'));
    }

    /**
     * Keeps an already bound context, including its root device and inode.
     *
     * No root is rebound and no filesystem access occurs until resolve().
     *
     * @see FilesystemContext::derive()
     */
    public static function bound(FilesystemContext $ctx, string $access): self
    {
        $resolved = [];
        $resolver = static function (?array $source, ?string $base) use ($ctx, $access, &$resolved): array {
            $key = $base ?? '';
            $resolved[$key] ??= $ctx->resolveInputPath(self::atBase($access, $base));

            return [$ctx, $resolved[$key]];
        };

        return new self($ctx->getOptions(), $resolver, relativeEntry: !str_starts_with($access, '/'));
    }

    /**
     * Retains option inheritance and defers its filesystem-dependent checks.
     *
     * Null and empty arrays inherit the current intent. Complete snapshots
     * remain complete snapshots; resolve() rejects any policy relaxation.
     *
     * @see FilesystemContext::derive()
     */
    public function derive(array|FilesystemOptions|null $options): self
    {
        if ($options === null || $options === []) {
            return $this;
        }

        $snapshot = is_array($options)
            ? FilesystemOptions::patch($this->options, $options)
            : $options;
        $context = null;

        // Erst die geerbte Bindung prüfen, danach die neue Policy einmalig binden.
        $resolver = static function (?array $source, ?string $base) use ($snapshot, &$context): array {
            [$inherited, $access] = $source;
            $context ??= $inherited->derive($access, $snapshot);

            return [$context, $access];
        };

        return new self($snapshot, $resolver, $this);
    }

    /**
     * Preserves a raw relative derivation and its optional local boundary.
     *
     * A file base uses its parent directory. Subpaths use resolveSubPath()
     * on every resolution, including its resolved symlink containment check.
     *
     * @see FilesystemContext::resolveRelative()
     * @see FilesystemContext::resolveSubPath()
     */
    public function relative(
        string $raw,
        bool $fileBase = false,
        bool $subpath = false
    ): self {
        $resolver = static function (?array $source, ?string $base) use ($raw, $fileBase, $subpath): array {
            [$context, $access] = $source;
            $base = $fileBase ? dirname($access) : $access;
            $target = $subpath
                ? $context->resolveSubPath($base, $raw)
                : $context->resolveRelative($base, $raw);

            return [$context, $target];
        };

        return new self($this->options, $resolver, $this);
    }

    /**
     * Defers selecting the parent until the original path has been checked.
     *
     * @see PhoreUri::getDirname()
     */
    public function parent(): self
    {
        return new self(
            $this->options,
            static fn (?array $source, ?string $base): array => [$source[0], dirname($source[1])],
            $this
        );
    }

    /**
     * Resolves another raw path under the same inherited authority.
     *
     * Its absolute path is anchored on first resolution; inherited provenance
     * is still checked before every subsequent use.
     *
     * @see FilesystemContext::resolveInputPath()
     */
    public function rebase(string $raw): self
    {
        $access = [];
        $resolver = static function (?array $source, ?string $base) use ($raw, &$access): array {
            [$context] = $source;
            $key = $base ?? '';
            $access[$key] ??= $context->resolveInputPath(self::atBase($raw, $base));

            return [$context, $access[$key]];
        };

        return new self($this->options, $resolver, $this, relativeEntry: false);
    }

    /**
     * Anchors a still-relative provenance chain before resolving any component.
     *
     * Raw dot segments and local subpath boundaries are checked at this base,
     * not at the process cwd. An already absolute view uses a normal rebase.
     * Existing contexts remain shared, including every bound root identity.
     *
     * @see PhoreUri::abs()
     */
    public function anchor(string $base, string $absoluteDisplay): self
    {
        if (!$this->hasRelativeEntry()) {
            return $this->rebase($absoluteDisplay);
        }

        return new self(
            $this->options,
            static fn (?array $source, ?string $base): array => $source,
            $this,
            relativeEntry: false,
            explicitBase: $base
        );
    }

    /**
     * Defers appending a validated suffix, including its optional leading dot.
     *
     * Replacement uses the same extension rule as PhoreUri. The original
     * path is authorized before changing its filename.
     *
     * @see PhoreUri::withFileExtension()
     */
    public function suffix(string $suffix, bool $replace = false): self
    {
        $resolver = static function (?array $source, ?string $base) use ($suffix, $replace): array {
            [$context, $access] = $source;
            if ($replace) {
                $access = preg_replace('/\.[a-z0-9]+$/i', '', $access) ?? $access;
            }

            return [$context, $context->resolveInputPath($access . $suffix)];
        };

        return new self($this->options, $resolver, $this);
    }

    /**
     * Resolves provenance and revalidates every inherited context in order.
     *
     * Binding uses derive(), not assertAccess(): atomic containment remains
     * enforced by the actual filesystem operation, as in the existing API.
     *
     * @return array{FilesystemContext, string}
     * @see PhoreUri::getFilesystemPathForOperation()
     */
    public function resolve(?string $base = null): array
    {
        $base = $this->explicitBase ?? $base;
        $source = $this->source?->resolve($base);
        [$context, $access] = ($this->resolver)($source, $base);
        $context->derive($access, null);

        return [$context, $access];
    }

    /**
     * Returns the requested immutable snapshot without binding a filesystem.
     *
     * Filesystem-dependent inheritance checks are deferred to resolve().
     *
     * @see FilesystemOptions
     */
    public function getOptions(): FilesystemOptions
    {
        return $this->options;
    }

    private function hasRelativeEntry(): bool
    {
        return $this->relativeEntry ?? $this->source?->hasRelativeEntry() ?? false;
    }

    private static function atBase(string $raw, ?string $base): string
    {
        return $base !== null && !str_starts_with($raw, '/')
            ? rtrim($base, '/') . '/' . $raw
            : $raw;
    }
}

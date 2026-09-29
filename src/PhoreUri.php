<?php

declare(strict_types=1);

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

    /**
     * Prüft den sichtbaren URI und optional einen zusätzlichen Dateinamen auf NUL-Bytes.
     *
     * Diese Methode validiert nur die String-Darstellung. Root-, Symlink- und
     * Dateisystem-Policies werden bei Pfadauflösung und realen Operationen geprüft.
     *
     * @param string|null $optFileName Optionaler zusätzlicher Dateiname.
     * @throws FilesystemException Wenn URI oder Dateiname ein NUL-Byte enthalten.
     * @return void
     * @see self::getFilesystemPathForOperation()
     * @example $uri->validate('index.html');
     */
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
     * Liefert den unveränderlichen Snapshot der aktuell gebundenen Filesystem-Policy.
     *
     * Damit kann Anwendungscode nachvollziehen, ob beispielsweise eine rootDir-
     * Begrenzung aktiv ist oder Symlinks verfolgt werden. Der zurückgegebene
     * Snapshot verändert den Context des Objekts nicht.
     *
     * @return FilesystemOptions Aktuell wirksame Optionen.
     * @see FilesystemOptions
     * @example assert($uri->getFilesystemOptions()->followSymlinks === true);
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

    /**
     * Liefert den übergeordneten Pfad als PhoreUri und übernimmt den Security-Context.
     *
     * Bei root-gebundenen Objekten darf der abgeleitete Parent die gebundene Root
     * nicht verlassen; andernfalls wird die Ableitung durch die Policy abgewiesen.
     *
     * @return self URI des übergeordneten Verzeichnisses.
     * @throws FilesystemPolicyViolationException Bei Verletzung einer geerbten Policy.
     * @see self::withParentDir()
     * @example assert((string) phore_uri('/srv/app/file.txt')->getDirname() === '/srv/app');
     */
    public function getDirname(): self
    {
        return $this->spawn(
            PhoreUri::class,
            dirname($this->uri),
            dirname($this->accessPath),
            $this->parentRelPath()
        );
    }

    /**
     * Liefert den letzten Pfadbestandteil; ein optionales Suffix wird entfernt.
     *
     * @param string $suffix Optional zu entfernendes Suffix.
     * @return string Basename des sichtbaren URI.
     * @see self::getFilename()
     * @example assert(phore_uri('/srv/app/file.txt')->getBasename() === 'file.txt');
     */
    public function getBasename(string $suffix = ''): string
    {
        return basename($this->uri, $suffix);
    }

    /**
     * Liefert die Dateiendung des sichtbaren URI ohne führenden Punkt.
     *
     * @return string Dateiendung oder leerer String.
     * @see self::withFileExtension()
     * @example assert(phore_uri('/srv/app/file.txt')->getExtension() === 'txt');
     */
    public function getExtension(): string
    {
        return pathinfo($this->uri, PATHINFO_EXTENSION);
    }

    /**
     * Liefert den Dateinamen ohne Verzeichnis und ohne Dateiendung.
     *
     * @return string Dateiname ohne Extension.
     * @see self::getBasename()
     * @example assert(phore_uri('/srv/app/file.txt')->getFilename() === 'file');
     */
    public function getFilename(): string
    {
        return pathinfo($this->uri, PATHINFO_FILENAME);
    }

    /**
     * Liefert das übergeordnete Verzeichnis als PhoreDirectory.
     *
     * Der bestehende FilesystemContext wird übernommen. Eine aktive rootDir-
     * Begrenzung kann daher verhindern, dass über diese Ableitung nach außen
     * navigiert wird.
     *
     * @return PhoreDirectory Übergeordnetes Verzeichnis.
     * @throws FilesystemPolicyViolationException Bei Verletzung einer geerbten Policy.
     * @see self::getDirname()
     * @example assert((string) phore_file('/srv/app/file.txt')->withDirName() === '/srv/app');
     */
    public function withDirName(): PhoreDirectory
    {
        return $this->spawn(
            PhoreDirectory::class,
            dirname($this->uri),
            dirname($this->accessPath),
            $this->parentRelPath()
        );
    }

    /**
     * Leitet einen echten Unterpfad der aktuellen Basis ab.
     *
     * Der Pfad darf syntaktisch nicht aus der Basis herauslaufen. Zusätzlich wird
     * der aufgelöste Pfad gegen die Basis geprüft: Symlinks sind standardmäßig
     * erlaubt, dürfen bei withSubPath() aber auch ohne rootDir nicht aus der
     * aktuellen Basis herausführen. Eine zusätzliche rootDir-Policy bleibt wirksam.
     *
     * @param string $subpath Relativer Unterpfad.
     * @return PhoreUri Abgeleiteter URI mit geerbtem Security-Context.
     * @throws PathOutOfBoundsException Wenn der Pfad oder ein Symlink die Basis verlässt.
     * @see self::withRelativePath()
     * @see self::assertRelativePath()
     * @example assert((string) phore_dir('/srv/app')->withSubPath('cache/data.json') === '/srv/app/cache/data.json');
     */
    public function withSubPath(string $subpath): PhoreUri
    {
        $relative = $this->assertRelativePath($subpath);
        $displayBase = $this instanceof PhoreFile ? dirname($this->uri) : $this->uri;
        $accessBase = $this instanceof PhoreFile ? dirname($this->accessPath) : $this->accessPath;
        $access = $this->filesystemContext->resolveSubPath($accessBase, $subpath);
        $display = self::joinDisplayPath($displayBase, $relative);

        $relPath = $this->relPath ?? [];
        foreach (explode('/', $relative) as $part) {
            if ($part !== '') {
                $relPath[] = $part;
            }
        }

        return $this->spawn(PhoreUri::class, $display, $access, $relPath);
    }

    /**
     * Leitet einen relativen Pfad ab, der auch Parent-Segmente enthalten darf.
     *
     * Im Unterschied zu withSubPath() ist die aktuelle Basis keine zusätzliche
     * lokale Sicherheitsgrenze. Eine konfigurierte rootDir-Policy bleibt jedoch
     * vollständig aktiv und verhindert sowohl lexikalische als auch aufgelöste
     * Symlink-Escapes. Verwende withSubPath(), wenn der Pfad zwingend unterhalb
     * der aktuellen Basis bleiben soll.
     *
     * @param string $relpath Nicht-leerer relativer Pfad.
     * @return PhoreUri Abgeleiteter URI mit geerbtem Security-Context.
     * @throws PathOutOfBoundsException Bei Verletzung einer gebundenen Root.
     * @see self::withSubPath()
     * @example assert((string) phore_dir('/srv/app/cache')->withRelativePath('../config') === '/srv/app/config');
     */
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

    /**
     * Prüft den sichtbaren URI gegen ein oder mehrere fnmatch()-Muster.
     *
     * Die Methode prüft nur Strings und führt keinen Dateisystemzugriff aus.
     *
     * @param string|string[] $patterns Einzelnes Muster oder Liste von Mustern.
     * @param int $flags Flags für PHP fnmatch().
     * @return bool true, sobald mindestens ein Muster passt.
     * @see https://www.php.net/fnmatch
     * @example assert(phore_uri('/srv/app/index.php')->fnmatch('*.php') === true);
     */
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

    /**
     * Prüft, ob der autorisierte Pfad auf ein Verzeichnis zeigt.
     *
     * Vor der Prüfung greifen die gebundenen Root-, Symlink-, Hardlink- und
     * Dateityp-Regeln. Symlinks werden standardmäßig verfolgt, sofern sie keine
     * aktive Sicherheitsgrenze verlassen.
     *
     * @return bool true für ein Verzeichnis.
     * @throws FilesystemPolicyViolationException Bei einem Policy-Verstoß.
     * @see self::isFile()
     * @example assert(phore_dir('/tmp')->isDirectory() === true);
     */
    public function isDirectory(): bool
    {
        $path = $this->filesystemContext->assertAccess($this->accessPath, 'isDirectory', true);

        return is_dir($path);
    }

    /**
     * Prüft, ob der autorisierte Pfad auf eine reguläre Datei zeigt.
     *
     * Vor der Prüfung werden alle gebundenen Filesystem-Policies angewendet.
     *
     * @return bool true für eine reguläre Datei.
     * @throws FilesystemPolicyViolationException Bei einem Policy-Verstoß.
     * @see self::isDirectory()
     * @example assert(phore_file(__FILE__)->isFile() === true);
     */
    public function isFile(): bool
    {
        $path = $this->filesystemContext->assertAccess($this->accessPath, 'isFile', true);

        return is_file($path);
    }

    /**
     * Prüft, ob der autorisierte Pfad existiert.
     *
     * Die Existenzprüfung umgeht keine Security-Policy: Ein außerhalb der Root
     * aufgelöstes Symlink-Ziel wird bereits vor dem Ergebnis abgewiesen.
     *
     * @return bool true, wenn Datei, Verzeichnis oder erlaubter Symlink existiert.
     * @throws FilesystemPolicyViolationException Bei einem Policy-Verstoß.
     * @see self::assertFile()
     * @example assert(phore_uri(__FILE__)->exists() === true);
     */
    public function exists(): bool
    {
        $path = $this->filesystemContext->assertAccess($this->accessPath, 'exists', true);

        return file_exists($path) || is_link($path);
    }

    /**
     * Prüft rein lexikalisch, ob der sichtbare URI unterhalb eines Pfades liegt.
     *
     * Diese Methode löst keine Symlinks auf und ist deshalb keine Security-Prüfung.
     * Für sicherheitsrelevante Ableitungen sind withSubPath() oder rootDir zu nutzen.
     *
     * @param string|PhoreUri $path Vergleichsbasis.
     * @return bool true bei gleichem Pfad oder lexikalischem Unterpfad.
     * @see self::withSubPath()
     * @example assert(phore_uri('/srv/app/cache')->isSubpathOf('/srv/app') === true);
     */
    public function isSubpathOf($path): bool
    {
        $candidate = self::normalizeDisplayPath((string) $this);
        $root = self::normalizeDisplayPath((string) $path);

        return $candidate === $root || str_starts_with($candidate, rtrim($root, '/') . '/');
    }

    /**
     * Liefert den Pfad als geprüftes Verzeichnis und kann ihn optional anlegen.
     *
     * Vor Prüfung und Erstellung gelten die gebundenen Security-Policies. Eine
     * aktive rootDir-Grenze und die Symlink-Regeln werden deshalb auch beim
     * Erstellen fehlender Verzeichnisse nicht umgangen.
     *
     * @param bool $createIfNotExisting Fehlendes Verzeichnis rekursiv anlegen.
     * @return PhoreDirectory Geprüftes Verzeichnis.
     * @throws FilesystemException Wenn kein gültiges Verzeichnis hergestellt werden kann.
     * @see PhoreDirectory::mkdir()
     * @example assert(phore_dir('/tmp')->assertDirectory()->isDirectory() === true);
     */
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

    /**
     * Liefert den Pfad als geprüfte reguläre Datei und kann sie optional anlegen.
     *
     * Verzeichnisse werden abgewiesen. Bei optionaler Erstellung werden Parent-
     * Verzeichnisse und Ziel erneut über den gebundenen FilesystemContext geprüft.
     *
     * @param bool $createIfNotExisting Fehlende Datei inklusive Parent-Verzeichnissen anlegen.
     * @return PhoreFile Geprüfte Datei.
     * @throws FilesystemException Wenn der Pfad keine reguläre Datei ist.
     * @see self::assertFileTarget()
     * @example assert(phore_file(__FILE__)->assertFile()->isFile() === true);
     */
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

    /**
     * Stellt sicher, dass der autorisierte Pfad lesbar ist.
     *
     * @return self Dasselbe Objekt für fluent usage.
     * @throws FileAccessException Wenn der Pfad nicht lesbar ist.
     * @throws FilesystemPolicyViolationException Bei einem Policy-Verstoß.
     * @see self::assertWritable()
     * @example assert(phore_file(__FILE__)->assertReadable()->isFile() === true);
     */
    public function assertReadable(): self
    {
        $path = $this->getFilesystemPathForOperation('assertReadable', false);
        if (!is_readable($path)) {
            throw new FileAccessException("Uri '{$this->uri}' is not readable.");
        }

        return $this;
    }

    /**
     * Stellt sicher, dass der autorisierte Pfad schreibbar ist.
     *
     * @return self Dasselbe Objekt für fluent usage.
     * @throws FileAccessException Wenn der Pfad nicht schreibbar ist.
     * @throws FilesystemPolicyViolationException Bei einem Policy-Verstoß.
     * @see self::assertReadable()
     * @example $file->assertWritable()->asFile()->set_contents('updated');
     */
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

    /**
     * Liefert den sichtbaren URI-String des Objekts.
     *
     * Der String enthält keinen FilesystemContext. Wer anschließend sicher auf
     * Dateien zugreifen will, sollte deshalb das PhoreUri-Objekt weiterreichen
     * statt nur den String zu transportieren.
     *
     * @return string Sichtbare Pfaddarstellung.
     * @see self::__toString()
     * @example assert(phore_uri('/srv/app')->getUri() === '/srv/app');
     */
    public function getUri(): string
    {
        $this->validate();

        return $this->uri;
    }

    /**
     * Gibt den sichtbaren URI als String zurück.
     *
     * Achtung: Durch die String-Konvertierung gehen gebundene Security-Policies
     * nicht in den String über. Für weitere Dateizugriffe das Objekt selbst nutzen.
     *
     * @return string Sichtbare Pfaddarstellung.
     * @see self::getUri()
     * @example assert((string) phore_uri('/srv/app') === '/srv/app');
     */
    public function __toString()
    {
        return $this->uri;
    }

    /**
     * Castet den URI zu PhoreFile und übernimmt den vollständigen Security-Context.
     *
     * @return PhoreFile Dateiobjekt mit denselben Restrictions.
     * @throws FilesystemPolicyViolationException Bei einer nicht erlaubten Ableitung.
     * @see self::asDirectory()
     * @example assert(phore_uri(__FILE__)->asFile() instanceof PhoreFile);
     */
    public function asFile(): PhoreFile
    {
        return $this->spawn(PhoreFile::class, $this->uri, $this->accessPath, $this->relPath);
    }

    /**
     * Hängt mehrere Pfadelemente nacheinander über withSubPath() an.
     *
     * Dadurch gilt für jedes Element dieselbe lokale Containment-Garantie wie bei
     * withSubPath(); Symlink-Escapes aus der jeweils aktuellen Basis werden abgewiesen.
     *
     * @param mixed ...$elements Anzuhängende Pfadelemente.
     * @return PhoreUri Zusammengesetzter URI.
     * @throws PathOutOfBoundsException Wenn ein Element die lokale Basis verlässt.
     * @see self::withSubPath()
     * @example assert((string) phore_dir('/srv/app')->join('cache', 'data.json') === '/srv/app/cache/data.json');
     */
    public function join(...$elements): PhoreUri
    {
        $path = $this;
        foreach ($elements as $element) {
            $element = ltrim((string) $element, '/');
            $path = $path->withSubPath($element);
        }

        return $path;
    }

    /**
     * Hängt einzelne Werte als URL-encodierte, nicht navigierende Pfadelemente an.
     *
     * Die Sonderwerte "." und ".." sowie leere Elemente werden abgewiesen. Jedes
     * Element wird anschließend über withSubPath() mit dessen Containment-Regeln
     * angefügt. Die Methode eignet sich für einzelne untrusted Namenssegmente,
     * nicht für bereits zusammengesetzte Pfade.
     *
     * @param mixed ...$elements Einzelne Pfadsegmente.
     * @return PhoreUri Sicher zusammengesetzter URI.
     * @throws \InvalidArgumentException Bei ".", ".." oder leerem Element.
     * @see self::withSubPath()
     * @example assert((string) phore_dir('/srv/app')->join_secure('a b') === '/srv/app/a+b');
     */
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

    /**
     * Wandelt einen relativen URI in einen absoluten URI um.
     *
     * Für bereits absolute Pfade wird nur ein gleichwertiges Objekt erzeugt. Bei
     * relativen Pfaden greift nach der Auflösung erneut der gebundene Security-Context.
     *
     * @param string|null $cwd Optionales Basisverzeichnis, sonst getcwd().
     * @return PhoreUri Absoluter URI.
     * @throws FilesystemException Wenn kein Arbeitsverzeichnis ermittelt werden kann.
     * @see self::rel()
     * @example assert((string) phore_uri('cache')->abs('/srv/app') === '/srv/app/cache');
     */
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

    /**
     * Stellt einen absoluten sichtbaren URI relativ zu einer Basis dar.
     *
     * Dies ändert nur die Darstellung; der interne Zugriffspfad und der gebundene
     * Security-Context bleiben erhalten. Der sichtbare Pfad muss unter rootPath liegen.
     *
     * @param string $rootPath Basis für die relative Darstellung.
     * @return PhoreUri URI mit relativer Darstellung.
     * @throws \InvalidArgumentException Wenn der sichtbare Pfad nicht unter rootPath liegt.
     * @see self::abs()
     * @example assert((string) phore_uri('/srv/app/cache')->rel('/srv/app') === 'cache');
     */
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

    /**
     * Erzeugt unterhalb der aktuellen Basis eine Datei mit geprüftem Dateinamen.
     *
     * Pfadseparatoren und NUL-Bytes im Dateinamen werden abgewiesen; die optionale
     * Extension muss alphanumerisch sein. Die Ableitung nutzt withSubPath() und
     * übernimmt damit dessen Root-, Symlink- und lokale Containment-Regeln.
     *
     * @param string $filename Dateiname ohne Pfadseparatoren.
     * @param string $fileExtension Optionale Extension ohne Punkt.
     * @return PhoreFile Abgeleitete Datei.
     * @throws \InvalidArgumentException Bei ungültigem Namen oder Extension.
     * @see self::withSubPath()
     * @example assert((string) phore_dir('/srv/app')->withFileName('index', 'html') === '/srv/app/index.html');
     */
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

    /**
     * Ergänzt oder ersetzt die Dateiendung des aktuellen Pfades.
     *
     * Pfadseparatoren und NUL-Bytes sind auch bei strictChecks=false verboten,
     * damit über die Extension kein zusätzlicher Pfad injiziert werden kann.
     * strictChecks=false lockert nur die Zeichenprüfung innerhalb der Extension.
     *
     * @param string $fileExtension Neue Extension ohne führenden Punkt.
     * @param bool $replaceExistingExtension Vorhandene Extension ersetzen.
     * @param bool $strictChecks Nur alphanumerische Zeichen erlauben.
     * @return PhoreFile Datei mit angepasster Extension und geerbtem Context.
     * @throws \InvalidArgumentException Bei ungültiger Extension.
     * @see self::withFileName()
     * @example assert((string) phore_file('/srv/app/page.md')->withFileExtension('html', true) === '/srv/app/page.html');
     */
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

    /**
     * Liefert das Parent-Verzeichnis als PhoreDirectory.
     *
     * Der Security-Context wird vererbt; eine aktive rootDir-Grenze kann deshalb
     * verhindern, dass ein Parent außerhalb der erlaubten Root erzeugt wird.
     *
     * @return PhoreDirectory Übergeordnetes Verzeichnis.
     * @throws FilesystemPolicyViolationException Bei Verletzung einer geerbten Policy.
     * @see self::withDirName()
     * @example assert((string) phore_file('/srv/app/file.txt')->withParentDir() === '/srv/app');
     */
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

    /**
     * Castet den URI zu PhoreDirectory und übernimmt den vollständigen Security-Context.
     *
     * @return PhoreDirectory Verzeichnisobjekt mit denselben Restrictions.
     * @throws FilesystemPolicyViolationException Bei einer nicht erlaubten Ableitung.
     * @see self::asFile()
     * @example assert(phore_uri('/tmp')->asDirectory() instanceof PhoreDirectory);
     */
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

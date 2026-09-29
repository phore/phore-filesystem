<?php

declare(strict_types=1);

namespace Phore\FileSystem;

use Phore\FileSystem\Exception\FileAccessException;
use Phore\FileSystem\Exception\FileNotFoundException;
use Phore\FileSystem\Exception\FilesystemException;
use Phore\FileSystem\Exception\FilesystemPolicyViolationException;
use Phore\FileSystem\Exception\UnsupportedFilesystemPolicyException;

class PhoreDirectory extends PhoreUri
{
    /**
     * Legt das Verzeichnis inklusive fehlender Parent-Verzeichnisse an.
     *
     * Bestehende Verzeichnisse bleiben unverändert. Vor der Erstellung werden
     * Ziel und letzter existierender Parent gegen Root-, Symlink- und weitere
     * Filesystem-Policies geprüft.
     *
     * @param int $createMask Unix-Rechtemaske für neu erzeugte Verzeichnisse.
     * @return self Dasselbe Verzeichnisobjekt.
     * @throws FilesystemException Wenn das Verzeichnis nicht angelegt werden kann.
     * @see PhoreUri::assertDirectory()
     * @example assert($dir->mkdir()->isDirectory() === true);
     */
    public function mkdir($createMask = 0777): self
    {
        $path = $this->getFilesystemPathForOperation('mkdir', true);

        if (!is_dir($path)) {
            $parent = dirname($path);
            while (!file_exists($parent)) {
                $next = dirname($parent);
                if ($next === $parent) {
                    break;
                }
                $parent = $next;
            }
            $this->filesystemContext->assertAccess($parent, 'mkdir parent', false);

            if (!@mkdir($path, $createMask, true) && !is_dir($path)) {
                $message = error_get_last()['message'] ?? 'unknown error';
                throw new FilesystemException(
                    "Cannot create directory '{$this->uri}': $message"
                );
            }
        }

        $this->getFilesystemPathForOperation('mkdir result', false);

        return $this;
    }

    /**
     * Entfernt dieses Verzeichnis, optional inklusive seines Inhalts.
     *
     * Bei recursive=true wird über den gemeinsamen Traversierungskern gelöscht;
     * dadurch greifen Root-, Symlink-, Dateityp- und Cycle-Prüfungen vor dem
     * Entfernen. Ein nicht existierendes Verzeichnis wird als no-op behandelt.
     *
     * @param bool $recursive Inhalt rekursiv entfernen.
     * @return self Dasselbe Verzeichnisobjekt.
     * @throws FilesystemException Wenn Traversierung oder rmdir fehlschlagen.
     * @see self::genWalk()
     * @example $dir->rmDir(recursive: true);
     */
    public function rmDir($recursive = false): self
    {
        if (!$this->exists()) {
            return $this;
        }

        if ($recursive === true) {
            foreach ($this->genWalk(null, true) as $entry) {
                if ($entry->isFile()) {
                    $entry->asFile()->unlink();
                    continue;
                }

                $entry->asDirectory()->rmDir(false);
            }
        }

        $path = $this->getFilesystemPathForOperation('rmdir', false);
        if (!@rmdir($path)) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new FilesystemException("Cannot rmdir '{$this->uri}': $message");
        }

        return $this;
    }

    /**
     * Ändert den Besitzer des autorisierten Verzeichnisses.
     *
     * Der Pfad wird vor chown() vollständig über den gebundenen FilesystemContext
     * geprüft; dadurch kann die Operation keine rootDir- oder Symlink-Grenze umgehen.
     *
     * @param string|int $owner Benutzername oder numerische User-ID.
     * @return self Dasselbe Verzeichnisobjekt.
     * @throws FilesystemException Wenn chown() fehlschlägt.
     * @see PhoreUri::getFilesystemOptions()
     * @example $dir->chown('www-data');
     */
    public function chown($owner): self
    {
        $path = $this->getFilesystemPathForOperation('chown', false);
        if (!@chown($path, $owner)) {
            throw new FilesystemException("Cannot chown '{$this->uri}' to user '$owner'.");
        }

        return $this;
    }

    /**
     * Visits direct entries that match the optional basename filter.
     *
     * The callback returning false stops the walk normally. Policy and access
     * failures remain exceptions and are never converted into this stop signal.
     *
     * @see self::genWalk()
     * @example $dir->walk(static fn(PhoreUri $entry) => true);
     */
    public function walk(callable $fn, ?string $filter = null): bool
    {
        foreach ($this->genWalk($filter, false) as $entry) {
            if ($fn($entry) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Visits regular files recursively through the common traversal core.
     *
     * Directory names are never filtered before recursion, so a file filter
     * cannot hide a directory or a filesystem policy violation.
     *
     * @see self::genWalk()
     * @example $dir->walkR(static fn(PhoreUri $file) => true, '*.md');
     */
    public function walkR(callable $fn, ?string $filter = null): bool
    {
        foreach ($this->genWalk($filter, true) as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            if ($fn($entry->asFile()) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Iterates directory entries using the bound filesystem restrictions.
     *
     * Files are yielded as PhoreFile, directories as PhoreDirectory. Recursive
     * traversal is child-first for directories and does not promise global
     * ordering. The basename filter affects yielded entries only, never whether
     * a directory is inspected.
     *
     * @return \Iterator<int, PhoreUri>
     * @throws FileAccessException
     * @throws FilesystemException
     * @see self::listFiles()
     * @example foreach ($dir->genWalk('*.md', true) as $entry) { echo $entry; }
     */
    public function genWalk(
        ?string $filter = null,
        bool $recursive = false,
        int $recursionLimit = 999
    ): \Iterator {
        if ($recursionLimit < 0) {
            throw new FilesystemException('recursionLimit must be zero or greater.');
        }

        $ancestors = [];
        $identity = $this->directoryIdentity();
        $ancestors[$identity] = true;

        yield from $this->genWalkInternal(
            $filter,
            $recursive,
            $recursionLimit,
            $ancestors
        );
    }

    /**
     * Returns regular files, optionally globally sorted by relative path.
     *
     * sort='path' materializes the full result and orders it with strcmp()
     * against slash-separated paths relative to this directory. The returned
     * PhoreFile objects preserve rootDir and every inherited restriction.
     *
     * @param 'path'|null $sort
     * @return list<PhoreFile>
     * @throws FilesystemException
     * @see self::genWalk()
     * @see PhoreUri::getRelPath()
     * @example $dir->listFiles('*.md', recursive: true, sort: 'path');
     */
    public function listFiles(
        ?string $filter = null,
        bool $recursive = false,
        int $recursionLimit = 999,
        ?string $sort = null
    ): array {
        if ($sort !== null && $sort !== 'path') {
            throw new \InvalidArgumentException("Unknown listFiles sort mode '$sort'.");
        }

        $files = [];
        foreach ($this->genWalk($filter, $recursive, $recursionLimit) as $path) {
            if ($path->isFile()) {
                $files[] = $path->asFile();
            }
        }

        if ($sort === 'path') {
            usort(
                $files,
                fn(PhoreFile $left, PhoreFile $right): int => strcmp(
                    $left->getRelPath($this) ?? '',
                    $right->getRelPath($this) ?? ''
                )
            );
        }

        return array_values($files);
    }

    /**
     * @return list<PhoreUri>
     * @throws FilesystemException
     * @see self::genWalk()
     * @example $entries = $dir->list(recursive: true);
     */
    public function list(
        $filter = null,
        bool $recursive = false,
        int $recursionLimit = 999
    ): array {
        return array_values(iterator_to_array(
            $this->genWalk($filter, $recursive, $recursionLimit),
            false
        ));
    }

    /**
     * Returns a globally path-sorted listing.
     *
     * String output is a data projection only and carries no filesystem
     * restrictions. Keep PhoreUri objects when later file access is required.
     *
     * @return list<PhoreUri|string>
     * @throws FilesystemException
     * @see self::genWalk()
     * @example $paths = $dir->getListSorted('*.md', true, true);
     */
    public function getListSorted(
        ?string $filter = null,
        bool $recursive = false,
        bool $returnRelPathAsString = false
    ): array {
        $entries = $this->list($filter, $recursive);

        usort(
            $entries,
            fn(PhoreUri $left, PhoreUri $right): int => strcmp(
                $left->getRelPath($this) ?? '',
                $right->getRelPath($this) ?? ''
            )
        );

        if ($returnRelPathAsString) {
            return array_map(
                fn(PhoreUri $entry): string => $entry->getRelPath($this) ?? '',
                $entries
            );
        }

        return $entries;
    }

    /**
     * Entpackt ein ZIP-Archiv per externem unzip-Prozess in dieses Verzeichnis.
     *
     * Externe Prozesse können die objektgebundene Filesystem-Policy nicht sicher
     * durchsetzen. Deshalb ist diese Methode für rootDir-gebundene oder atomar
     * eingeschränkte Objekte absichtlich gesperrt. Ohne solche Restrictions wird
     * das Zielverzeichnis vor dem Prozessaufruf dennoch autorisiert.
     *
     * @param string|PhoreUri $filename Pfad zum ZIP-Archiv.
     * @return void
     * @throws UnsupportedFilesystemPolicyException Wenn externe Prozesse nicht erlaubt sind.
     * @see PhoreUri::assertExternalProcessAllowed()
     * @example $dir->importZipFile('/tmp/archive.zip');
     */
    public function importZipFile($filename)
    {
        $this->assertExternalProcessAllowed('importZipFile');
        $this->getFilesystemPathForOperation('importZipFile destination', false);
        $this->validate((string) $filename);

        phore_exec(
            'unzip :zipfile -d :folder',
            ['zipfile' => $filename, 'folder' => $this->accessPath]
        );
    }

    /**
     * Sucht rekursiv die erste reguläre Datei, deren vollständiger Pfad zum Regex passt.
     *
     * Die Suche verwendet listFiles() und übernimmt damit alle Traversierungs- und
     * Security-Prüfungen. Treffer werden als gebundene PhoreFile-Objekte zurückgegeben.
     *
     * @param string $regex PCRE-Muster.
     * @param array|null $matches Optionales preg_match()-Ergebnis des Treffers.
     * @return PhoreFile Erster passender Treffer.
     * @throws FileNotFoundException Wenn keine Datei passt.
     * @see self::listFiles()
     * @example assert($dir->getFileByPattern('/composer\\.json$/')->getBasename() === 'composer.json');
     */
    public function getFileByPattern(string $regex, &$matches = null): PhoreFile
    {
        foreach ($this->listFiles(recursive: true) as $file) {
            if (preg_match($regex, (string) $file, $matches)) {
                return $file;
            }
        }

        throw new FileNotFoundException(
            "No file matching pattern '$regex' found in directory '{$this->uri}'."
        );
    }

    /**
     * Kopiert alle regulären Dateien rekursiv in ein Zielverzeichnis.
     *
     * Relative Pfade werden mit getRelPath() bestimmt und im Ziel über withSubPath()
     * neu aufgebaut. Dadurch gelten sowohl der Source- als auch der Target-Context;
     * insbesondere können Root- oder Symlink-Grenzen nicht durch den Kopiervorgang
     * umgangen werden.
     *
     * @param PhoreDirectory $targetDir Zielverzeichnis.
     * @return void
     * @throws FilesystemException Bei Traversierungs-, Lese- oder Schreibfehlern.
     * @see self::moveTo()
     * @example $source->copyTo($target); assert($target->withSubPath('file.txt')->exists());
     */
    public function copyTo(PhoreDirectory $targetDir): void
    {
        $this->getFilesystemPathForOperation('copy source directory', false);
        $targetDir->getFilesystemPathForOperation('copy target directory', false);

        foreach ($this->listFiles(recursive: true, sort: 'path') as $source) {
            $relative = $source->getRelPath($this);
            if ($relative === null) {
                throw new FilesystemPolicyViolationException(
                    "Cannot derive relative path while copying '{$source->getUri()}'."
                );
            }

            $target = $targetDir->withSubPath($relative)->asFile();
            $target->mkdir()->set_contents($source->get_contents());
        }
    }

    /**
     * Verschiebt alle regulären Dateien rekursiv in ein Zielverzeichnis.
     *
     * Technisch werden Dateien über die geprüften Phore-Operationen kopiert und
     * anschließend aus der Quelle gelöscht. Source- und Target-Security-Contexts
     * bleiben wirksam; bei einem Fehler kann daher bereits ein Teil kopiert sein.
     *
     * @param PhoreDirectory $targetDir Zielverzeichnis.
     * @return void
     * @throws FilesystemException Bei Traversierungs-, Lese-, Schreib- oder Löschfehlern.
     * @see self::copyTo()
     * @example $source->moveTo($target); assert($target->withSubPath('file.txt')->exists());
     */
    public function moveTo(PhoreDirectory $targetDir): void
    {
        $this->getFilesystemPathForOperation('move source directory', false);
        $targetDir->getFilesystemPathForOperation('move target directory', false);

        foreach ($this->listFiles(recursive: true, sort: 'path') as $source) {
            $relative = $source->getRelPath($this);
            if ($relative === null) {
                throw new FilesystemPolicyViolationException(
                    "Cannot derive relative path while moving '{$source->getUri()}'."
                );
            }

            $target = $targetDir->withSubPath($relative)->asFile();
            $target->mkdir()->set_contents($source->get_contents());
            $source->unlink();
        }
    }

    /**
     * @param array<string, true> $ancestors
     * @return \Generator<int, PhoreUri>
     */
    private function genWalkInternal(
        ?string $filter,
        bool $recursive,
        int $recursionLimit,
        array $ancestors
    ): \Generator {
        $directoryPath = $this->getFilesystemPathForOperation('walk directory', false);
        $dirFp = @opendir($directoryPath);
        if ($dirFp === false) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new FileAccessException(
                "Cannot open path '{$this->uri}' for indexing: $message"
            );
        }

        try {
            while (($name = readdir($dirFp)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                // The entry is authorized before any result filter is applied.
                $entry = $this->withSubPath($name);
                $isDirectory = $entry->isDirectory();
                $isFile = $entry->isFile();

                if (!$isDirectory && !$isFile) {
                    throw new UnsupportedFilesystemPolicyException(
                        "Unsupported filesystem entry '{$entry->getUri()}'."
                    );
                }

                if ($isDirectory && $recursive) {
                    if ($recursionLimit === 0) {
                        throw new FilesystemException(
                            "Recursion limit reached before entering '{$entry->getUri()}'."
                        );
                    }

                    $directory = $entry->asDirectory();
                    $identity = $directory->directoryIdentity();
                    if (isset($ancestors[$identity])) {
                        throw new FilesystemPolicyViolationException(
                            "Directory cycle detected at '{$directory->getUri()}'."
                        );
                    }

                    $childAncestors = $ancestors;
                    $childAncestors[$identity] = true;

                    yield from $directory->genWalkInternal(
                        $filter,
                        true,
                        $recursionLimit - 1,
                        $childAncestors
                    );
                }

                if ($filter !== null && !fnmatch($filter, $name)) {
                    continue;
                }

                yield $isFile ? $entry->asFile() : $entry->asDirectory();
            }
        } finally {
            closedir($dirFp);
        }
    }

    private function directoryIdentity(): string
    {
        $path = $this->getFilesystemPathForOperation('directory identity', false);
        $stat = @stat($path);
        if ($stat === false) {
            throw new FileAccessException(
                "Cannot stat directory '{$this->uri}' for traversal."
            );
        }

        return (string) $stat['dev'] . ':' . (string) $stat['ino'];
    }
}

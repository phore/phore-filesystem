<?php

declare(strict_types=1);

use Phore\FileSystem\FilesystemOptions;
use Phore\FileSystem\PhoreDirectory;
use Phore\FileSystem\PhoreFile;
use Phore\FileSystem\PhoreTempFile;
use Phore\FileSystem\PhoreUri;

/**
 * Creates a URI while preserving inherited filesystem restrictions.
 *
 * Security: A PhoreUri input keeps its complete context. options=null and []
 * therefore mean inheritance, not a reset to defaults. A string is a new
 * trusted entry point.
 *
 * @param string|PhoreUri $uri
 * @param array{
 *   rootDir?: string|null,
 *   followSymlinks?: bool,
 *   allowHardLinks?: bool,
 *   requireAtomicContainment?: bool
 * }|FilesystemOptions|null $options
 * @throws \Phore\FileSystem\Exception\FilesystemException
 * @see FilesystemOptions::fromAssoc()
 * @example phore_uri('/srv/site/docs', ['rootDir' => '/srv/site/docs']);
 */
function phore_uri(
    string|PhoreUri $uri,
    array|FilesystemOptions|null $options = null
): PhoreUri {
    return new PhoreUri($uri, options: $options);
}

/**
 * Creates a file view while preserving inherited filesystem restrictions.
 *
 * rootDir=null means no root boundary only for a new string entry point.
 * followSymlinks defaults to true. With rootDir set, resolved symlink targets
 * must remain inside that root. Inherited restrictions can only remain unchanged
 * or become stricter; attempts to relax them throw.
 *
 * @param string|PhoreUri $filename
 * @param array{
 *   rootDir?: string|null,
 *   followSymlinks?: bool,
 *   allowHardLinks?: bool,
 *   requireAtomicContainment?: bool
 * }|FilesystemOptions|null $options
 * @throws \Phore\FileSystem\Exception\FilesystemException
 * @see FilesystemOptions::fromAssoc()
 * @example phore_file('/srv/site/docs/page.md', ['rootDir' => '/srv/site/docs']);
 */
function phore_file(
    string|PhoreUri $filename,
    array|FilesystemOptions|null $options = null
): PhoreFile {
    return new PhoreFile($filename, options: $options);
}

function phore_tempfile(): PhoreTempFile
{
    return new PhoreTempFile();
}

/**
 * Creates a directory view while preserving inherited filesystem restrictions.
 *
 * @param string|PhoreUri $directory
 * @param array{
 *   rootDir?: string|null,
 *   followSymlinks?: bool,
 *   allowHardLinks?: bool,
 *   requireAtomicContainment?: bool
 * }|FilesystemOptions|null $options
 * @throws \Phore\FileSystem\Exception\FilesystemException
 * @see FilesystemOptions::fromAssoc()
 * @example phore_dir('/srv/site/docs', ['rootDir' => '/srv/site/docs']);
 */
function phore_dir(
    string|PhoreUri $directory,
    array|FilesystemOptions|null $options = null
): PhoreDirectory {
    return new PhoreDirectory($directory, options: $options);
}

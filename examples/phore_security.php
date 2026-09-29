<?php

require __DIR__ . '/../vendor/autoload.php';

use Phore\FileSystem\Exception\PathOutOfBoundsException;

$base = new \Phore\FileSystem\PhoreTempDir();
$allowed = $base->withSubPath('site')->assertDirectory(true);
$sibling = $base->withSubPath('sibling')->assertDirectory(true);

$sibling->withSubPath('secret.txt')->asFile()->set_contents('outside');
$allowed->withSubPath('index.md')->asFile()->set_contents('# Start');

$root = phore_dir($allowed, [
    'rootDir' => (string) $allowed,
]);

// Alle abgeleiteten Datei-/Directory-Objekte behalten rootDir und No-Follow.
$page = phore_file($root->withSubPath('index.md'));
assert($page->get_contents() === '# Start');
assert($page->getFilesystemOptions()->rootDir === (string) $allowed);
assert($page->getFilesystemOptions()->followSymlinks === false);

// Die Darstellungsbasis fuer Walk-Ergebnisse aendert die Sicherheits-Root nicht.
$paths = array_map(
    static fn($file): string => $file->getRelPath($root),
    $root->listFiles(recursive: true, sort: 'path')
);
assert($paths === ['index.md']);

// Ein geerbtes Objekt darf weder per ".." noch per Cast zum Sibling ausbrechen.
try {
    phore_file($page)
        ->withRelativePath('../sibling/secret.txt')
        ->asFile()
        ->get_contents();

    throw new \RuntimeException('Expected sibling access to be rejected.');
} catch (PathOutOfBoundsException) {
    // Erwarteter Sicherheitsfehler: kein Consumer-Workaround erforderlich.
}

echo "ok\n";

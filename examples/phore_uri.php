<?php

require __DIR__ . '/../vendor/autoload.php';

$root = new \Phore\FileSystem\PhoreTempDir();

// assertDirectory(true) verlangt ein Verzeichnis und darf es bei Bedarf anlegen.
// Ohne true wird ein fehlendes Verzeichnis als Fehler durchgereicht.
$dir = $root->withSubPath('sub')->assertDirectory(true);
$file = $dir->withFileName('demo', 'txt')->set_contents('x');

// Explizite Voraussetzungen: Assert statt if (!is_...) { throw ...; }.
$dir->assertReadable()->assertWritable();
$existingFile = $root->withSubPath('sub/demo.txt')->assertFile();

// asFile()/asDirectory() wechseln nur den Objekttyp, sie bestaetigen keine Existenz.
$futureDirectory = $root->withSubPath('later')->asDirectory();
$futureDirectory->mkdir();
$futureFile = $futureDirectory->withSubPath('note.txt')->asFile();
$futureFile->set_contents('neu');
$text = $existingFile->get_contents();
assert($text === 'x');

$clean = phore_uri((string) $root . '//sub/./demo.txt')->clean();
$join = $root->join('sub', 'demo.txt');
$joinSecure = $root->join_secure('space name');
$relative = $file->withRelativePath('../other.txt');
$abs = phore_uri('sub/demo.txt')->abs((string) $root);
$rel = $file->rel((string) $root);

// Ohne rootDir ist dies ein unbeschraenkter Neueinstieg. Sobald ein Root gebunden
// ist, behalten withSubPath(), rel() und Typwechsel den Sicherheitskontext.
$isSubpath = $file->isSubpathOf((string) $root);
$matches = $file->fnmatch('*.txt');

assert((string) $clean === (string) $file && (string) $join === (string) $file);
assert($joinSecure->getBasename() === 'space+name');
assert((string) $relative === (string) $root . '/other.txt');
assert((string) $abs === (string) $file && (string) $rel === 'sub/demo.txt');
assert($isSubpath && $matches);

echo "ok\n";

<?php

require __DIR__ . '/../vendor/autoload.php';

$root = phore_dir('/tmp/phore-filesystem-uri-' . getmypid() . '-' . bin2hex(random_bytes(4)));
$root->rmDir(true)->mkdir();

try {
    // Erstellt sicher ein Verzeichnis und eine Datei unter /tmp.
    $dir = phore_uri((string)$root)->withSubPath('sub')->assertDirectory(true)->assertReadable()->assertWritable();
    $file = phore_uri((string)$root)->withSubPath('sub/demo.txt')->assertFile(true)->set_contents('x');

    // Baut und normalisiert Pfade.
    $clean = phore_uri((string)$root . '//sub/./demo.txt')->clean();
    $join = phore_uri((string)$root)->join('sub', 'demo.txt');
    $joinSecure = phore_uri((string)$root)->join_secure('space name');
    $relative = $file->withRelativePath('../other.txt');
    $abs = phore_uri('sub/demo.txt')->abs((string)$root);
    $rel = $file->rel((string)$root);

    // Wechselt generisch zwischen Uri-, File- und Dir-Objekten.
    $asFile = $file->asFile();
    $asDir = $dir->asDirectory();

    // Prüft Muster und Subpfade.
    $isSubpath = $file->isSubpathOf((string)$root);
    $matches = $file->fnmatch('*.txt');

    if (
        (string)$clean !== (string)$file ||
        (string)$join !== (string)$file ||
        $joinSecure->getBasename() !== 'space+name' ||
        (string)$relative !== (string)$root . '/other.txt' ||
        (string)$abs !== (string)$file ||
        (string)$rel !== 'sub/demo.txt' ||
        !$asFile->isFile() ||
        !$asDir->isDirectory() ||
        !$isSubpath ||
        !$matches
    ) {
        throw new RuntimeException('Unexpected uri result');
    }

    echo "ok\n";
} finally {
    $root->rmDir(true);
}

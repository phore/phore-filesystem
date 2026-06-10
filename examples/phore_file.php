<?php

require __DIR__ . '/../vendor/autoload.php';

$root = phore_dir('/tmp/phore-filesystem-file-' . getmypid() . '-' . bin2hex(random_bytes(4)));
$root->rmDir(true)->mkdir();

try {
    $dir = phore_dir((string)$root . '/data')->mkdir();

    // Wechselt von phore_dir zu phore_file und schreibt Text.
    // set_contents(), append_content() und get_contents() arbeiten intern mit File-Locking.
    $file = $dir->withFileName('demo', 'txt')->set_contents("A\n")->append_content('B');

    // Wechselt von phore_file zurück zum Verzeichnis.
    $dirA = $file->withDirName();
    $dirB = $file->getDirname()->asDirectory();

    // Liest Dateiname, Basename und Extension.
    $basename = $file->getBasename();
    $filename = $file->getFilename();
    $extension = $file->getExtension();

    // Ersetzt die Dateiendung.
    $logFile = $file->withFileExtension('log', true)->set_contents('log');

    // Nutzt createPath(), touch(), get_contents_array(), tail(), copyTo(), rename() und unlink().
    // Auch diese High-Level-Methoden bauen auf dem normalen File-Zugriff mit Locking auf.
    $appFile = phore_file((string)$root . '/logs/app.txt')->createPath()->touch()->set_contents("a\nb\nc");
    $lines = $appFile->get_contents_array();
    $tail = $appFile->tail(1);
    $copy = phore_file((string)$root . '/copy/app.txt');
    $appFile->copyTo($copy);
    $renamed = phore_file((string)$root . '/copy/app-renamed.txt');
    $copy->rename((string)$renamed);
    $renamed->unlink();

    // Prüft Dateistatus und Größe.
    $exists = $appFile->exists();
    $isFile = $appFile->isFile();
    $size = $appFile->getFilesize();

    // Nutzt phore_uri() für generisches Path-Building sowie JSON und YAML.
    $json = phore_uri((string)$root)->withSubPath('data/demo.json')->assertFile(true)->set_json(['hello' => 'json'], true)->get_json();
    $yaml = phore_uri((string)$root)->join('data', 'demo.yml')->assertFile(true)->set_yaml(['hello' => 'yaml'])->get_yaml();

    if (
        $file->get_contents() !== "A\nB" ||
        (string)$dirA !== (string)$dir ||
        (string)$dirB !== (string)$dir ||
        $basename !== 'demo.txt' ||
        $filename !== 'demo' ||
        $extension !== 'txt' ||
        $logFile->getBasename() !== 'demo.log' ||
        $lines !== ['a', 'b', 'c'] ||
        $tail !== 'c' ||
        !$exists ||
        !$isFile ||
        $size !== 5 ||
        !$copy->getDirname()->isDirectory() ||
        $renamed->exists() ||
        $json !== ['hello' => 'json'] ||
        $yaml !== ['hello' => 'yaml']
    ) {
        throw new RuntimeException('Unexpected file result');
    }

    echo "ok\n";
} finally {
    $root->rmDir(true);
}

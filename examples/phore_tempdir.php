<?php

require __DIR__ . '/../vendor/autoload.php';

use Phore\FileSystem\PhoreTempDir;

// Ein PhoreTempDir ist ein normales phore_dir-Objekt mit Auto-Cleanup.
// Mehr Dir-Operationen siehe: examples/phore_dir.php
$tmp = new PhoreTempDir();
$name = (string)$tmp;

// Nutzt das TempDir wie jedes andere Verzeichnis.
$tmp->withFileName('demo', 'txt')->set_contents('ok');

if (!$tmp->withSubPath('demo.txt')->isFile()) {
    throw new RuntimeException('TempDir failed');
}

unset($tmp);
if (file_exists($name)) {
    throw new RuntimeException('TempDir was not removed');
}

echo "ok\n";

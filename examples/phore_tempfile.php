<?php

require __DIR__ . '/../vendor/autoload.php';

use Phore\FileSystem\PhoreTempFile;

// TempFiles sind normale phore_file-Objekte.
// Mehr File-Operationen siehe: examples/phore_file.php und examples/csv_parse.php
$tmpA = phore_tempfile()->set_contents('tmp-a');
$tmpB = (new PhoreTempFile('demo-', '.txt'))->set_contents('tmp-b');
$names = [(string)$tmpA, (string)$tmpB];

if ($tmpA->get_contents() !== 'tmp-a' || $tmpB->get_contents() !== 'tmp-b') {
    throw new RuntimeException('TempFile failed');
}

unset($tmpA, $tmpB);
foreach ($names as $name) {
    if (file_exists($name)) {
        throw new RuntimeException('Temp file was not removed: ' . $name);
    }
}

echo "ok\n";

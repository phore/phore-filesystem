<?php

require __DIR__ . '/../vendor/autoload.php';

$root = phore_dir('/tmp/phore-filesystem-stream-' . getmypid() . '-' . bin2hex(random_bytes(4)));
$root->rmDir(true)->mkdir();

try {
    // Öffnet eine Datei als FileStream über fopen().
    $file = phore_file((string)$root . '/demo.txt')->touch();
    $stream = $file->fopen('w+');

    // Die normalen PHP-Stream-Funktionen sind als Methoden gewrappt: flock(), fwrite(), datasync(), rewind(), fgets(), feof(), tell(), fclose().
    $stream->flock(LOCK_EX);
    $stream->fwrite("line-1\nline-2\n", $written);
    $stream->datasync();
    $stream->rewind();

    $lines = [];
    while (!$stream->feof()) {
        $line = $stream->fgets();
        if ($line === false)
            continue;
        $lines[] = rtrim($line, "\n");
    }

    $pos = $stream->tell();
    $size = $stream->getSize();
    $metaMode = $stream->getMetadata('mode');
    $closedFile = $stream->fclose();

    if (
        $written !== 14 ||
        $lines !== ['line-1', 'line-2'] ||
        $pos !== $size ||
        $size !== 14 ||
        $metaMode !== 'w+' ||
        (string)$closedFile !== (string)$file
    ) {
        throw new RuntimeException('Unexpected stream result');
    }

    echo "ok\n";
} finally {
    $root->rmDir(true);
}

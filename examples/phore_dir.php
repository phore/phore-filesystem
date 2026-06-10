<?php

require __DIR__ . '/../vendor/autoload.php';

$root = phore_dir('/tmp/phore-filesystem-dir-' . getmypid() . '-' . bin2hex(random_bytes(4)));
$root->rmDir(true)->mkdir();

try {
    // Legt ein kleines Verzeichnis mit Dateien an.
    $src = phore_dir((string)$root . '/src')->mkdir();
    $src->withFileName('a', 'txt')->set_contents('A');
    $src->withSubPath('sub')->asDirectory()->mkdir();
    $src->withSubPath('sub/b.txt')->assertFile(true)->set_contents('B');

    // Iteriert rekursiv mit genWalk().
    $genWalk = [];
    foreach ($src->genWalk('*.txt', true) as $file) {
        $genWalk[] = $file->getRelPath();
    }
    sort($genWalk);

    // Listet Dateien auf verschiedenen Wegen.
    $list = array_map(fn($u) => $u->getRelPath(), $src->list('*.txt', true));
    $listFiles = array_map(fn($f) => $f->getRelPath(), $src->listFiles('*.txt', true));
    $sorted = $src->getListSorted('*.txt', true, true);
    sort($list);
    sort($listFiles);
    sort($sorted);

    // Iteriert flach und rekursiv per Callback.
    $walk = [];
    $src->walk(function ($u) use (&$walk) { $walk[] = $u->getBasename(); });
    sort($walk);

    $walkR = [];
    $src->walkR(function ($u) use (&$walkR) { if ($u->isFile()) $walkR[] = $u->getRelPath(); });
    sort($walkR);

    // Findet eine Datei per Regex.
    $found = $src->getFileByPattern('/b\.txt$/');

    // Kopiert und verschiebt ein Verzeichnis.
    $copy = phore_dir((string)$root . '/copy')->mkdir();
    $src->copyTo($copy);
    $move = phore_dir((string)$root . '/move')->mkdir();
    $move->withSubPath('sub')->asDirectory()->mkdir();
    $copy->moveTo($move);

    if (
        $genWalk !== ['a.txt', 'sub/b.txt'] ||
        $list !== ['a.txt', 'sub/b.txt'] ||
        $listFiles !== ['a.txt', 'sub/b.txt'] ||
        $sorted !== ['a.txt', 'sub/b.txt'] ||
        $walk !== ['a.txt', 'sub'] ||
        $walkR !== ['a.txt', 'sub/b.txt'] ||
        $found->getBasename() !== 'b.txt' ||
        !$move->withSubPath('sub/b.txt')->isFile() ||
        $copy->withSubPath('sub/b.txt')->isFile()
    ) {
        throw new RuntimeException('Unexpected dir result');
    }

    echo "ok\n";
} finally {
    $root->rmDir(true);
}

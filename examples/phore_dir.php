<?php

require __DIR__ . '/../vendor/autoload.php';

$root = new \Phore\FileSystem\PhoreTempDir();
$src = phore_dir((string) $root . '/src')->assertDirectory(true);
$src->withFileName('a', 'txt')->set_contents('A');
$src->withSubPath('sub/b.txt')->asFile()->mkdir()->set_contents('B');

// Normalfall: rekursiv Dateien lesen, ohne eigene Walk-Methode oder Fehler-Wrapper.
$contents = [];
foreach ($src->listFiles('*.txt', true) as $file) {
    $contents[$file->getRelPath()] = $file->get_contents();
}
ksort($contents);
assert($contents === ['a.txt' => 'A', 'sub/b.txt' => 'B']);

// Alternative: Eintraege schrittweise verarbeiten statt eine Dateiliste zu materialisieren.
$genWalk = [];
foreach ($src->genWalk('*.txt', true) as $entry) {
    $genWalk[] = $entry->assertFile()->getRelPath();
}
sort($genWalk);

$list = array_map(fn($entry) => $entry->getRelPath(), $src->list('*.txt', true));
$sorted = $src->getListSorted('*.txt', true, true);
sort($list);

// Callback-Varianten: walk() ist flach; walkR() besucht rekursiv die Blaetter.
$walk = [];
$src->walk(function ($entry) use (&$walk) {
    $walk[] = $entry->getBasename();
});
sort($walk);

$walkR = [];
$src->walkR(function ($entry) use (&$walkR) {
    $walkR[] = $entry->assertFile()->getRelPath();
});
sort($walkR);
$found = $src->getFileByPattern('/b\\.txt$/');

$copy = $root->withSubPath('copy')->assertDirectory(true);
$src->copyTo($copy);
$move = $root->withSubPath('move')->assertDirectory(true);
$move->withSubPath('sub')->assertDirectory(true);
$copy->moveTo($move);
$move->withSubPath('sub/b.txt')->assertFile();

assert($genWalk === ['a.txt', 'sub/b.txt']);
assert($list === ['a.txt', 'sub/b.txt'] && $sorted === ['a.txt', 'sub/b.txt']);
assert($walk === ['a.txt', 'sub'] && $walkR === ['a.txt', 'sub/b.txt']);
assert($found->getBasename() === 'b.txt');
assert(!$copy->withSubPath('sub/b.txt')->exists());

// Die heutigen Walk-Methoden sind keine Symlink-Sandbox; siehe Options-Entwurf.
echo "ok\n";

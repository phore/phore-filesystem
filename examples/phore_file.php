<?php

require __DIR__ . '/../vendor/autoload.php';

// Das Temp-Verzeichnis wird automatisch aufgeraeumt; Fehler bleiben unveraendert sichtbar.
$root = new \Phore\FileSystem\PhoreTempDir();
$dir = $root->withSubPath('data')->assertDirectory(true);

// Normalfall: direkt schreiben und lesen, ohne exists(), Vorab-Guards oder try/catch.
// Die Dateioperationen uebernehmen Zugriffspruefung, Locking und Fehlerdiagnose.
$file = $dir->withFileName('demo', 'txt')->set_contents("A\n")->append_content('B');
$text = $file->get_contents();
assert($text === "A\nB");

$dirA = $file->withDirName();
$dirB = $file->getDirname()->asDirectory();
$basename = $file->getBasename();
$filename = $file->getFilename();
$extension = $file->getExtension();
$logFile = $file->withFileExtension('log', true)->set_contents('log');

// mkdir()/createPath() bereiten Elternverzeichnisse vor, set_contents() legt Dateien an.
$appFile = $root->withSubPath('logs/app.txt')->asFile()->createPath()->touch()->set_contents("a\nb\nc");
$lines = $appFile->get_contents_array();
$tail = $appFile->tail(1);
$size = $appFile->getFilesize();
$copy = $root->withSubPath('copy/app.txt')->asFile();
$appFile->copyTo($copy);
$renamed = $root->withSubPath('copy/app-renamed.txt')->asFile();
$copy->rename((string) $renamed);
$renamed->unlink();

// JSON/YAML nicht selbst lesen und parsen: Formatfehler erhalten Dateikontext von Phore.
$jsonFile = $root->withSubPath('data/demo.json')->asFile()->set_json(['hello' => 'json'], true);
$yamlFile = $root->withSubPath('data/demo.yml')->asFile()->set_yaml(['hello' => 'yaml']);
$json = $jsonFile->get_json();
$yaml = $yamlFile->get_yaml();

// assert*() ist fuer eine eigenstaendige Voraussetzung gedacht, nicht vor jedem Lesen.
// Hier wird das nach der Kopie erwartete Verzeichnis explizit verlangt.
$copy->getDirname()->assertDirectory();

// Erwartete Beispielwerte, keine vorgeschaltete Datei-Pruefschicht.
assert((string) $dirA === (string) $dir && (string) $dirB === (string) $dir);
assert($basename === 'demo.txt' && $filename === 'demo' && $extension === 'txt');
assert($logFile->getBasename() === 'demo.log');
assert($lines === ['a', 'b', 'c'] && $tail === 'c' && $size === 5);
assert(!$renamed->exists());
assert($json === ['hello' => 'json'] && $yaml === ['hello' => 'yaml']);

echo "ok\n";

<?php

require __DIR__ . '/../vendor/autoload.php';

$root = phore_dir('/tmp/phore-filesystem-csv-' . getmypid() . '-' . bin2hex(random_bytes(4)));
$root->rmDir(true)->mkdir();

try {
    $csv = phore_file((string)$root . '/demo.csv')->set_csv([
        ['name' => 'Alice', 'age' => '30'],
        ['name' => 'Bob', 'age' => '31'],
    ], ['name', 'age']);

    $rows = [];
    foreach ($csv->parseCSV() as $row) {
        $rows[] = $row;
    }

    if ($rows !== [
        ['name' => 'Alice', 'age' => '30'],
        ['name' => 'Bob', 'age' => '31'],
    ]) {
        throw new RuntimeException('Unexpected CSV result: ' . json_encode($rows));
    }

    echo "ok\n";
} finally {
    $root->rmDir(true);
}

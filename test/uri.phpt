<?php
/**
 * Created by PhpStorm.
 * User: matthes
 * Date: 17.07.18
 * Time: 18:26
 */

namespace Test;

require __DIR__ . "/../vendor/autoload.php";

use Phore\FileSystem\Exception\FileAccessException;
use Phore\FileSystem\Exception\FilesystemException;
use Phore\FileSystem\Exception\PathOutOfBoundsException;
use Tester\Assert;

\Tester\Environment::setup();



$p = phore_uri(__DIR__ . "/mock");


$p->assertDirectory();

Assert::exception(function () use ($p) {
    $p->assertFile();
}, FilesystemException::class);

Assert::exception(function() use ($p) {
    $p->withSubPath("../some/file");
}, PathOutOfBoundsException::class);


Assert::equal(__DIR__ , (string)$p->withRelativePath("../"));


Assert::equal(['some' => 'json'], $p->withSubPath("demo.json")->assertFile()->get_json());


$f = phore_uri(__DIR__ . "/mock/demo.json")->assertFile();
$f->withSubPath("./demo.yml")->assertFile();

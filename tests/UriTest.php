<?php
/**
 * Created by PhpStorm.
 * User: matthias
 * Date: 20.12.18
 * Time: 14:54
 */

namespace Test;


use Phore\FileSystem\Exception\FilesystemException;
use Phore\FileSystem\Exception\PathOutOfBoundsException;
use PHPUnit\Framework\TestCase;

class UriTest extends TestCase
{


    public function testThrowsExceptionIfNotAFile()
    {
        $p = phore_uri(__DIR__ . "/mock");
        $this->expectException(FilesystemException::class);

        $p->assertDirectory();
        $p->assertFile();
    }


    public function testThrowsOutOfBoundExceptionIfRelativePathUnderrunsBondary()
    {
        $p = phore_uri(__DIR__ . "/mock");
        $this->expectException(PathOutOfBoundsException::class);
        $p->withSubPath("../some/file");
    }

    public function testUri()
    {
        $p = phore_uri(__DIR__ . "/mock");

        // Relative Path may exceed directory boundaries
        $this->assertEquals(__DIR__ , (string)$p->withRelativePath("../"));


        $this->assertEquals(['some' => 'json'], $p->withSubPath("demo.json")->assertFile()->get_json());


        $f = phore_uri(__DIR__ . "/mock/demo.json")->assertFile();
        $f->withSubPath("./demo.yml")->assertFile();
    }

}
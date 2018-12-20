<?php
/**
 * Created by PhpStorm.
 * User: matthias
 * Date: 20.12.18
 * Time: 14:59
 */

namespace Test;


use Phore\FileSystem\PhoreTempFile;
use PHPUnit\Framework\TestCase;

class PhoreTempFileTest extends TestCase
{

    private function createTempFileAndReturnName()
    {
        $tmp = new PhoreTempFile();
        $tmp->fopen("w")->fwrite("abc");
        return $tmp->getUri();
    }

    public function testTempFileIsDeletedIfPointerOpen()
    {
       $name = $this->createTempFileAndReturnName();
       $this->assertEquals(false, file_exists($name));
    }

}
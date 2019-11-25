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


    public function testTail()
    {
        $tmp = new PhoreTempFile();
        $tmp->set_contents("123456789");

        $this->assertEquals("89", $tmp->tail(2));

    }
    public function testTailWithSmallInput()
    {
        $tmp = new PhoreTempFile();
        $tmp->set_contents("123");

        $this->assertEquals("123", $tmp->tail(6));

    }
}
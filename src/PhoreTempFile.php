<?php
/**
 * Created by PhpStorm.
 * User: matthias
 * Date: 30.08.18
 * Time: 14:16
 */

namespace Phore\FileSystem;


use Phore\FileSystem\Exception\FilesystemException;

class PhoreTempFile
{

    private $name;
    
    public function __construct($prefix="")
    {
        $this->name = tempnam(sys_get_temp_dir(), $prefix);
        if ($this->name === false)
            throw new FilesystemException("Can't create new tempoary file.");
    }
    
    public function __toString()
    {
        return $this->name;
    }

}

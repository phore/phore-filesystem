<?php
/**
 * Created by PhpStorm.
 * User: matthias
 * Date: 30.08.18
 * Time: 14:16
 */

namespace Phore\FileSystem;


use Phore\FileSystem\Exception\FilesystemException;

class PhoreTempFile extends PhoreFile
{
    /**
     * PhoreTempFile constructor.
     * @param string $prefix
     * @param string $suffix    Provide e.g. extension by adding ".ext" - default empty
     * @throws FilesystemException
     */
    public function __construct($prefix="", $suffix="")
    {
        $name = sys_get_temp_dir() . "/". uniqid($prefix) . $suffix;
        if ($name === false)
            throw new FilesystemException("Can't create new tempoary file.");
        $this->unlinkOnClose();
        parent::__construct($name);
    }
}

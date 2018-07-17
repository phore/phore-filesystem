<?php
/**
 * Created by PhpStorm.
 * User: matthes
 * Date: 17.07.18
 * Time: 14:04
 */

namespace Phore\FileSystem;


use Phore\FileSystem\Exception\FileAccessException;
use Phore\FileSystem\Exception\FileNotFoundException;
use Phore\FileSystem\Exception\PathOutOfBoundsException;

class Path
{

    private $path;

    public function __construct(string $path)
    {
        if (substr ($path, 0, 1) !== "/")
            throw new \InvalidArgumentException("Path '$path' must be absolute path starting with '/'");
        $this->path = $path;
    }


    public function dirname () : self
    {
        return new self(dirname($this->path));
    }


    public function withSubPath (string $subpath) : self
    {
        $parts = explode("/", $subpath);
        $ret = [];
        foreach ($parts as $part) {
            if ($part == "")
                continue;
            if ($part == "." || $part == "")
                continue;
            if ($part == "..") {
                if (count ($ret) == 0)
                    throw new PathOutOfBoundsException("SubPath is out of bounds: $subpath");
                array_pop($ret);
                continue;
            }
            $ret[] = $part;
        }

        return new self($this->path .= "/" . implode("/", $ret));
    }

    public function isDirectory () : bool
    {
        return file_exists($this->path) && is_dir($this->path);
    }

    public function isFile () : bool
    {
        return file_exists($this->path) && is_file($this->path);
    }


    public function assertFile () : self
    {
        if (file_exists($this->path) && is_file($this->path))
            return $this;
        throw new FileNotFoundException("Path '$this->path' is not a valid file.");
    }

    public function assertReadable () : self
    {
        if ( ! is_readable($this->path))
            throw new FileAccessException("Path '$this->path' is not readable");
        return $this;
    }

    public function assertWritable () : self
    {
        if ( ! is_writable($this->path))
            throw new FileAccessException("Path '$this->path' is not writable");
        return $this;
    }


    public function __toString()
    {
        return $this->path;
    }

    public function toFile() : File
    {
        return new File($this->path);
    }
}
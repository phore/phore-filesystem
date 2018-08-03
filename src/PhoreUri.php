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
use Phore\FileSystem\Exception\FilesystemException;
use Phore\FileSystem\Exception\PathOutOfBoundsException;

class PhoreUri
{

    protected $uri;

    public function __construct(string $uri)
    {
        $this->uri = $uri;
    }


    public function getDirname () : self
    {
        return new self(dirname($this->uri));
    }


    public function getBasename() : string
    {
        return basename($this->uri);
    }


    public function withDirName() : PhoreDirectory
    {
        return new PhoreDirectory(dirname($this->uri));
    }


    public function withSubPath (string $subpath) : PhoreUri
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
        $startUri = $this->uri;
        if ($this instanceof PhoreFile) {
            $startUri = dirname($startUri);
        }
        return new PhoreUri($startUri .= "/" . implode("/", $ret));
    }

    public function withRelativePath (string $relpath) : PhoreUri
    {
        $startUri = $this->uri;
        if ($this instanceof PhoreFile) {
            $startUri = dirname($startUri);
        }
        $prefix = "";
        if (substr($startUri, 0, 1) === "/")
            $prefix = "/"; // Absolute path

        $parts = explode("/", $startUri . "/"  . $relpath);
        $ret = [];
        foreach ($parts as $part) {
            if ($part == "")
                continue;
            if ($part == "." || $part == "")
                continue;
            if ($part == "..") {
                if (count ($ret) == 0)
                    throw new PathOutOfBoundsException("SubPath is out of bounds: $relpath");
                array_pop($ret);
                continue;
            }
            $ret[] = $part;
        }

        return new PhoreUri($prefix . implode("/", $ret));
    }


    public function isDirectory () : bool
    {
        return file_exists($this->uri) && is_dir($this->uri);
    }

    public function isFile () : bool
    {
        return file_exists($this->uri) && is_file($this->uri);
    }


    public function assertDirectory () : PhoreDirectory
    {
        if (file_exists($this->uri) && is_dir($this->uri))
            return new PhoreDirectory($this->uri);
        throw new FilesystemException("Uri '$this->uri' is not a valid directory.");
    }



    public function assertFile () : PhoreFile
    {
        if (file_exists($this->uri) && is_file($this->uri))
            return new PhoreFile($this->uri);
        throw new FilesystemException("Uri '$this->uri' is not a valid file.");
    }

    public function assertReadable () : self
    {
        if ( ! is_readable($this->uri))
            throw new FileAccessException("Uri '$this->uri' is not readable");
        return $this;
    }

    public function assertWritable () : self
    {
        if ( ! is_writable($this->uri))
            throw new FileAccessException("Uri '$this->uri' is not writable");
        return $this;
    }


    public function __toString()
    {
        return $this->uri;
    }

    public function asFile() : PhoreFile
    {
        return new PhoreFile($this->uri);
    }

    public function asDirectory() : PhoreDirectory
    {
        return new PhoreDirectory($this->uri);
    }
}

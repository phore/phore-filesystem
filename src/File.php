<?php
/**
 * Created by PhpStorm.
 * User: matthes
 * Date: 17.07.18
 * Time: 14:19
 */

namespace Phore\FileSystem;


use Phore\FileSystem\Exception\FileAccessException;
use Phore\FileSystem\Exception\FileNotFoundException;
use Phore\FileSystem\Exception\FileParsingException;
use Phore\FileSystem\Exception\PathOutOfBoundsException;

class File
{
    public function __construct(string $filename)
    {
        $this->filename = $filename;
    }
    public function path() : Path
    {
        return new Path($this->filename);
    }

    public function fopen(string $mode) : FileStream
    {
        $fp = @fopen($this->filename, $mode);
        if ( ! $fp)
            throw new FileAccessException("fopen($this->filename): " . error_get_last()["message"]);
        return new FileStream($fp, $this);
    }

    private function _read_content_locked ()
    {
        $file = $this->fopen("r")->flock(LOCK_SH);
        $buf = "";
        while ( ! $file->feof())
            $buf .= $file->fread(1024);
        $file->flock(LOCK_UN);
        $file->fclose();
        return $buf;
    }

    private function _write_content_locked ($content, bool $append = false)
    {
        $mode = "w+";
        if ($append)
            $mode = "a+";
        $this->fopen($mode)->flock(LOCK_EX)->fwrite($content)->flock(LOCK_UN)->fclose();
    }

    /**
     * Set or get Contents of file
     *
     * @param string|null $setContent
     *
     * @return File|string
     * @throws FileAccessException
     */
    public function get_contents()
    {
        try {
            if (func_num_args() > 0) {

                return $this;
            }
            return $this->_read_content_locked();
        } catch (\Exception $e) {
            throw new $e($e->getMessage(), $e->getCode(), $e);
        }
    }


    public function set_contents (string $contents) : self
    {
        try {
            $this->_write_content_locked($contents);
        } catch (\Exception $e) {
            throw new $e($e->getMessage(), $e->getCode(), $e);
        }
        return $this;
    }


    /**
     * @param string $appendContent
     *
     * @return File
     */
    public function append_content(string $appendContent) : self
    {
        try {
            $this->_write_content_locked($appendContent, true);
            return $this;
        } catch (\Exception $e) {
            throw new $e($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function fileSize () : int
    {
        return filesize($this->filename);
    }

    /**
     * @param null $content
     *
     * @return $this|array
     * @throws \Exception
     */
    public function get_yaml()
    {
        try {
            $textData = $this->get_contents();
        } catch (\Exception $e) {
            throw new $e($e->getMessage(), $e->getCode(), $e);
        }
        ini_set("yaml.decode_php", "0");
        $ret = yaml_parse($textData);
        if ($ret === false) {
            $err = error_get_last();
            throw new FileParsingException(
                "YAML Parsing of file '{$this->filename}' failed: {$err["message"]}",
                0
            );
        }
        return $ret;
    }

    /**
     * @param null $content
     *
     * @return $this|array
     * @throws FileParsingException
     */
    public function get_json()
    {
        $json = json_decode($this->get_contents(), true);
        if ($json === null) {
            throw new FileParsingException(
                "JSON Parsing of file '{$this->filename}' failed: " . json_last_error_msg()
            );
        }
        return $json;
    }

    /**
     * @param null $content
     *
     * @return $this|array
     * @throws FileParsingException
     */
    public function get_serialized()
    {
        $serialize = unserialize($this->get_contents());
        if ($serialize === null) {
            throw new FileParsingException(
                "Unserialize of file '{$this->filename}' failed."
            );
        }
        return $serialize;
    }

    public function isDirectory () : bool
    {
        return file_exists($this->filename) && is_dir($this->filename);
    }

    public function isFile () : bool
    {
        return file_exists($this->filename) && is_file($this->filename);
    }

    public function rename ($newName) : self
    {
        if ( ! @rename($this->filename, $newName))
            throw new FileAccessException("Cannot rename file '{$this->filename}' to '{$newName}': " . implode(" ", error_get_last()));
        $this->filename = $newName;
        return $this;
    }

    public function unlink() : self
    {
        if ( ! @unlink($this->filename))
            throw new FileAccessException("Cannot unlink file '{$this->filename}': " . implode(" ", error_get_last()));
        return $this;
    }

    public function mustExist()
    {
        if ( ! file_exists($this->filename))
            throw new FileNotFoundException("File '$this->filename' not found");
        return $this;
    }

    public function __toString()
    {
        return $this->filename;
    }
}
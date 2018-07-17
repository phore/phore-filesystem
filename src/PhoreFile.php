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

class PhoreFile extends PhoreUri
{

    public function fopen(string $mode) : FileStream
    {
        $fp = @fopen($this->uri, $mode);
        if ( ! $fp)
            throw new FileAccessException("fopen($this->uri): " . error_get_last()["message"]);
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
     * @return PhoreFile|string
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
     * @return PhoreFile
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
        return filesize($this->uri);
    }

    /**
     * @param null $content
     *
     * @return $this|array
     * @throws \Exception
     */
    public function get_yaml() : array
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
                "YAML Parsing of file '{$this->uri}' failed: {$err["message"]}",
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
    public function get_json() : array
    {
        $json = json_decode($this->get_contents(), true);
        if ($json === null) {
            throw new FileParsingException(
                "JSON Parsing of file '{$this->uri}' failed: " . json_last_error_msg()
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
    public function get_serialized() : array
    {
        $serialize = unserialize($this->get_contents());
        if ($serialize === null) {
            throw new FileParsingException(
                "Unserialize of file '{$this->uri}' failed."
            );
        }
        return $serialize;
    }


    public function rename ($newName) : self
    {
        if ( ! @rename($this->uri, $newName))
            throw new FileAccessException("Cannot rename file '{$this->uri}' to '{$newName}': " . implode(" ", error_get_last()));
        $this->filename = $newName;
        return $this;
    }

    public function unlink() : self
    {
        if ( ! @unlink($this->uri))
            throw new FileAccessException("Cannot unlink file '{$this->uri}': " . implode(" ", error_get_last()));
        return $this;
    }

}
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
use Phore\FileSystem\Exception\FilesystemException;
use Phore\FileSystem\Exception\PathOutOfBoundsException;

class PhoreFile extends PhoreUri
{

    private $unlinkOnClose = false;
    
    public function unlinkOnClose() : PhoreFile
    {
        $this->unlinkOnClose = true;
        return $this;
    }
    
    
    public function __destruct()
    {
        if ($this->unlinkOnClose) {
            if ($this->isFile())
                $this->unlink();
        }

    }


    public function fopen(string $mode) : FileStream
    {
        $stream = new FileStream($this, $mode);
        return $stream;
    }
    
    
    public function gzopen(string $mode) : GzFileStream
    {
        $stream = new GzFileStream($this, $mode);
        return $stream;
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
     * @throws FileNotFoundException
     * @throws FileAccessException
     */
    public function get_contents()
    {
        try {
            return $this->_read_content_locked();
        } catch (\Exception $e) {
            if ( ! $this->exists())
                throw new FileNotFoundException("File '$this->uri' not found.");
            throw new FilesystemException($e->getMessage(), $e->getCode(), $e);
        }
    }


    /**
     * Return the last x bytes of the file
     *
     * @param int $bytes
     * @return string
     * @throws FileAccessException
     */
    public function tail(int $bytes)
    {
        $stream = $this->fopen("r");
        $stream->seek($this->getFilesize() - $bytes);
        $buf = "";
        while ( ! $stream->feof()) {
            $buf .= $stream->fread(8000);
        }
        $stream->close();
        return $buf;
    }

    /**
     * Output the file directly to echo or a callback function if specified.
     *
     * @param callable|null $callback
     * @param int $chunkSize
     * @throws FileAccessException
     */
    public function passthru(callable $callback = null, int $chunkSize=64000)
    {
        $stream = $this->fopen("r");
        while ( ! $stream->feof()) {
            $buf = $stream->fread($chunkSize);
            if ($callback !== null) {
                $callback($buf);
            } else {
                echo $buf;
            }
        }

    }


    public function set_contents (string $contents) : self
    {
        try {
            $this->_write_content_locked($contents);
        } catch (\Exception $e) {
            throw new FilesystemException($e->getMessage(), $e->getCode(), $e);
        }
        return $this;
    }


    public function chown (string $owner) : self
    {
        if ( ! chown($this->uri, $owner))
            throw new FilesystemException("Cannot chown $this->uri to user $owner");
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
            if ($e instanceof \ErrorException)
                throw new \Exception($e->getMessage(), $e->getCode(), $e);
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
     * @throws FileNotFoundException
     * @throws \Exception
     */
    public function get_yaml() : array
    {
        try {
            $textData = $this->get_contents();
        } catch (\Exception $e) {
            throw new $e($e->getMessage(), $e->getCode(), $e);
        }
        try {

            $ret = phore_yaml_decode($textData);
        } catch (\InvalidArgumentException $e) {
            throw new FileParsingException($e->getMessage() . " in file '{$this->getUri()}'", 0, $e);
        }
        return $ret;
    }

    /**
     * @param null $content
     *
     * @return $this|array
     * @throws FileNotFoundException
     * @throws FileParsingException
     */
    public function get_json() : array
    {
        try {
            return phore_json_decode($this->get_contents());
        } catch (\InvalidArgumentException $e) {
            throw new FileParsingException(
                "JSON Parsing of file '{$this->uri}' failed: " . $e->getMessage(), 0, $e
            );
        }
    }

    /**
     * @param null $content
     *
     * @return $this|array
     * @throws FileParsingException
     */
    public function set_json(array $data) : self
    {
        $this->set_contents(phore_json_encode($data));
        return $this;
    }

    
    public function set_yaml(array $data) : self
    {
        $this->set_contents(yaml_emit($data));
        return $this;
    }
    
    
    /**
     * @param $allowedClasses bool|string[]
     *
     * @see phore_unserialize()
     * @return $this|array
     * @throws FileNotFoundException
     * @throws FileParsingException
     */
    public function get_serialized($allowedClasses=false) : array
    {
        $serialize = phore_unserialize($this->get_contents(), $allowedClasses);
        if ($serialize === null) {
            throw new FileParsingException(
                "Unserialize of file '{$this->uri}' failed."
            );
        }
        return $serialize;
    }

    /**
     * @param $data
     * @return PhoreFile
     * @throws FilesystemException
     */
    public function set_serialized($data) : self
    {
        $this->set_contents(phore_serialize($data));
        return $this;
    }


    private $csvOptions = null;

    public function withCsvOptions(bool $parseHeader=false, string $delimiter=",", string $enclosure='"', string $escape="\\") : self
    {
        $new = clone ($this);
        $new->csvOptions = [
            "parseHeader" => $parseHeader,
            "delimiter" => $delimiter,
            "enclosure" => $enclosure,
            "escape" => $escape,
            "headerMap" => null
        ];
        return $new;
    }

    public function walkCSV (callable $callback) : bool
    {
        if ($this->csvOptions === null)
            throw new \InvalidArgumentException("Unset csv options. Call withCsvOptions() before!");
        if ($this->csvOptions["parseHeader"] === true) {
            // Todo: Parse the header
        }
        $stream = $this->fopen("r");
        $index = 0;
        while (!$stream->feof()) {
            $row = $stream->freadcsv(0, $this->csvOptions["delimiter"], $this->csvOptions["enclosure"], $this->csvOptions["escape"]);
            if ($row === null)
                continue;
            $ret = $callback($row, $index++);
            if ($ret === false) {
                $stream->fclose();
                return false;
            }
        }
        return true;
    }

    /**
     * @param string $mode
     * @return PhoreFile
     */
    public function touch($mode="0777") : self
    {
        if ( ! file_exists($this->uri)) {
            mkdir(dirname($this->uri),  $mode, true);
            touch($this->uri);
            chmod($this->uri, $mode);
        }
        if ( ! is_file($this->uri))
            throw new FilesystemException("touch file '$this->uri': Uri exists but is not a file.");
        return $this;
    }


    public function getFilesize() : int
    {
        return filesize($this->uri);
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

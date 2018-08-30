<?php
/**
 * Created by PhpStorm.
 * User: matthes
 * Date: 17.07.18
 * Time: 14:23
 */

namespace Phore\FileSystem;


use Phore\FileSystem\Exception\FileAccessException;


class FileStream
{
    protected $res;
    /**
     * @var string
     */
    protected $filename;
    
    
    public function __construct(string $filename, string $mode)
    {
        $this->fopen($filename, $mode);
    }


    public function fopen (string $filename, string $mode) : FileStream
    {
        $this->filename = $filename;
        $this->res = fopen($filename, $mode);
        if ( ! $this->res)
            throw new FileAccessException("fopen($this->filename): " . error_get_last()["message"]);
        return $this;
    }
    
    public function flock(int $operation) : FileStream {
        if ( ! @flock($this->res, $operation)) {
            throw new FileAccessException("Cannot flock('$this->filename'): " . error_get_last()["message"]);
        }
        return $this;
    }
    public function feof() : bool {
        return @feof($this->res);
    }
    public function fwrite ($data) : FileStream {
        if (false === @fwrite($this->res, $data))
            throw new FileAccessException("Cannot get fwrite('$this->filename'): " . error_get_last()["message"]);
        return $this;
    }
    public function fread (int $length) : string {
        if (false === ($data = @fread($this->res, $length)))
            throw new FileAccessException("Cannot get fread('$this->filename'): " . error_get_last()["message"]);
        return $data;
    }
    public function fgets (int $length=null) {
        if (false === ($data = @fgets($this->res, $length)) && ! @feof($this->res))
            throw new FileAccessException("Cannot get fgets('$this->filename'): " . error_get_last()["message"]);
        return $data;
    }

    public function freadcsv (int $length=0, string $delimiter=",", string $enclosure='"', string $escape_char = "\\")
    {
        $data = fgetcsv($this->res, $length, $delimiter, $enclosure, $escape_char);
        if (null === $data)
            throw new FileAccessException("Cannot get fgetcsv('$this->filename'): " . error_get_last()["message"]);
        if ($data === false)
            return null;
        return $data;
    }
    public function fputcsv (array $fields, string $delimiter=",", string $enclosure='"', string $escape_char = "\\") : FileStream {
        if (false === @fputcsv($this->res, $fields, $delimiter, $enclosure, $escape_char))
            throw new FileAccessException("Cannot get fgets('$this->filename'): " . error_get_last()["message"]);
        return $this;
    }
    public function fclose() : PhoreFile {
        if (false === @fclose($this->res))
            throw new FileAccessException("Cannot get fclose('$this->filename'): " . error_get_last()["message"]);
        return $this->file;
    }

    public function seek(int $offset) : FileStream
    {
        fseek($this->res, $offset);
        return $this;
    }

    public function getRessource()
    {
        return $this->res;
    }

}

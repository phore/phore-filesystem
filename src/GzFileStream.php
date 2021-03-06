<?php
/**
 * Created by PhpStorm.
 * User: matthes
 * Date: 17.07.18
 * Time: 14:23
 */

namespace Phore\FileSystem;


use Phore\FileSystem\Exception\FileAccessException;


class GzFileStream extends FileStream
{
    public function fopen (string $filename, string $mode) : FileStream
    {
        $this->file = $filename;
        $this->res = gzopen($filename, $mode);
        if ( ! $this->res)
            throw new FileAccessException("fopen($this->file): " . error_get_last()["message"]);
        return $this;
    }
    public function flock(int $operation) : FileStream 
    {
        if ( ! @flock($this->res, $operation)) {
            throw new FileAccessException("Cannot flock('$this->file'): " . error_get_last()["message"]);
        }
        return $this;
    }
    public function feof() : bool 
    {
        return @gzeof($this->res);
    }
    public function fwrite ($data, &$bytesWritten=null) : FileStream 
    {
        if (false === @gzwrite($this->res, $data))
            throw new FileAccessException("Cannot get gzwrite('$this->file'): " . error_get_last()["message"]);
        return $this;
    }
    public function fread (int $length) : string 
    {
        if (false === ($data = @gzread($this->res, $length)))
            throw new FileAccessException("Cannot get fread('$this->file'): " . error_get_last()["message"]);
        return $data;
    }
    public function fgets (int $length=null) 
    {
        if (false === ($data = @gzgets($this->res, $length)) && ! @feof($this->res))
            throw new FileAccessException("Cannot get fgets('$this->file'): " . error_get_last()["message"]);
        return $data;
    }

    public function freadcsv (int $length=0, string $delimiter=",", string $enclosure='"', string $escape_char = "\\")
    {
        $data = str_getcsv($this->fgets($length), $delimiter, $enclosure, $escape_char);
        if (null === $data)
            throw new FileAccessException("Cannot get fgetcsv('$this->file'): " . error_get_last()["message"]);
        if ($data === false)
            return null;
        return $data;
    }
    public function fputcsv (array $fields, string $delimiter=",", string $enclosure='"', string $escape_char = "\\") : FileStream 
    {
        if (false === @fputcsv($this->res, $fields, $delimiter, $enclosure, $escape_char))
            throw new FileAccessException("Cannot get fgets('$this->file'): " . error_get_last()["message"]);
        return $this;
    }
    public function fclose() : PhoreFile 
    {
        if (false === @gzclose($this->res))
            throw new FileAccessException("Cannot get gzclose('$this->file'): " . error_get_last()["message"]);
        return new PhoreFile($this->file);
    }

    public function seek($offset, $whence = SEEK_SET) 
    {
        gzseek($this->res, $offset);
    }

    public function getRessource()
    {
        return $this->res;
    }

}

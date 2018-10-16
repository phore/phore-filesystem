<?php
/**
 * Created by PhpStorm.
 * User: matthes
 * Date: 17.07.18
 * Time: 14:23
 */

namespace Phore\FileSystem;


use Phore\FileSystem\Exception\FileAccessException;
use Psr\Http\Message\StreamInterface;


class FileStream implements StreamInterface
{
    protected $res;
    /**
     * @var PhoreFile
     */
    protected $file;

    /**
     * FileStream constructor.
     * @param PhoreFile $file
     * @param string $mode
     * @throws FileAccessException
     */
    public function __construct(PhoreFile $file, string $mode)
    {
        $this->file = $file;
        $this->fopen((string)$file, $mode);
    }


    public function getFileObject() : PhoreFile
    {
        return $this->file;
    }


    protected function fopen (string $filename, string $mode) : FileStream
    {
        $this->file = $filename;
        $this->res = fopen($filename, $mode);
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
        return @feof($this->res);
    }
    public function fwrite ($data, &$bytesWritten=null) : FileStream
    {
        if (false === ($bytesWritten = @fwrite($this->res, $data)))
            throw new FileAccessException("Cannot get fwrite('$this->file'): " . error_get_last()["message"]);
        return $this;
    }
    public function fread (int $length) : string 
    {
        if (false === ($data = @fread($this->res, $length)))
            throw new FileAccessException("Cannot get fread('$this->file'): " . error_get_last()["message"]);
        return $data;
    }
    public function fgets (int $length=null) 
    {
        if (false === ($data = @fgets($this->res, $length)) && ! @feof($this->res))
            throw new FileAccessException("Cannot get fgets('$this->file'): " . error_get_last()["message"]);
        return $data;
    }

    public function freadcsv (int $length=0, string $delimiter=",", string $enclosure='"', string $escape_char = "\\")
    {
        $data = fgetcsv($this->res, $length, $delimiter, $enclosure, $escape_char);
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
        if (false === @fclose($this->res))
            throw new FileAccessException("Cannot get fclose('$this->file'): " . error_get_last()["message"]);
        return new PhoreFile($this->file);
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

    /**
     * Reads all data from the stream into a string, from the beginning to end.
     *
     * This method MUST attempt to seek to the beginning of the stream before
     * reading data and read the stream until the end is reached.
     *
     * Warning: This could attempt to load a large amount of data into memory.
     *
     * This method MUST NOT raise an exception in order to conform with PHP's
     * string casting operations.
     *
     * @see http://php.net/manual/en/language.oop5.magic.php#object.tostring
     * @return string
     */
    public function __toString()
    {
        if ($this->isSeekable())
            $this->seek(0);
        return $this->getContents();
    }

    /**
     * Closes the stream and any underlying resources.
     *
     * @return void
     */
    public function close()
    {
        $this->fclose();
    }

    /**
     * Separates any underlying resources from the stream.
     *
     * After the stream has been detached, the stream is in an unusable state.
     *
     * @return resource|null Underlying PHP stream, if any
     */
    public function detach()
    {
        $this->fclose();
    }

    /**
     * Get the size of the stream if known.
     *
     * @return int|null Returns the size in bytes if known, or null if unknown.
     */
    public function getSize()
    {
        return null;
    }

    /**
     * Returns the current position of the file read/write pointer
     *
     * @return int Position of the file pointer
     * @throws \RuntimeException on error.
     */
    public function tell()
    {
        return ftell($this->res);
    }

    /**
     * Returns true if the stream is at the end of the stream.
     *
     * @return bool
     */
    public function eof()
    {
        return $this->feof();
    }

    /**
     * Returns whether or not the stream is seekable.
     *
     * @return bool
     */
    public function isSeekable()
    {
        return true;
    }

    /**
     * Seek to the beginning of the stream.
     *
     * If the stream is not seekable, this method will raise an exception;
     * otherwise, it will perform a seek(0).
     *
     * @see seek()
     * @link http://www.php.net/manual/en/function.fseek.php
     * @throws \RuntimeException on failure.
     */
    public function rewind()
    {
        $this->seek(0);
    }

    /**
     * Returns whether or not the stream is writable.
     *
     * @return bool
     */
    public function isWritable()
    {
        return true;
    }

    /**
     * Write data to the stream.
     *
     * @param string $string The string that is to be written.
     * @return int Returns the number of bytes written to the stream.
     * @throws \RuntimeException on failure.
     */
    public function write($string)
    {
        $this->fwrite($string, $bytesWritten);
        return $bytesWritten;
    }

    /**
     * Returns whether or not the stream is readable.
     *
     * @return bool
     */
    public function isReadable()
    {
        return true;
    }

    /**
     * Read data from the stream.
     *
     * @param int $length Read up to $length bytes from the object and return
     *     them. Fewer than $length bytes may be returned if underlying stream
     *     call returns fewer bytes.
     * @return string Returns the data read from the stream, or an empty string
     *     if no bytes are available.
     * @throws \RuntimeException if an error occurs.
     */
    public function read($length)
    {
        return $this->fread($length);
    }

    /**
     * Returns the remaining contents in a string
     *
     * @return string
     * @throws \RuntimeException if unable to read or an error occurs while
     *     reading.
     */
    public function getContents()
    {
        return $this->getContents();
    }

    /**
     * Get stream metadata as an associative array or retrieve a specific key.
     *
     * The keys returned are identical to the keys returned from PHP's
     * stream_get_meta_data() function.
     *
     * @link http://php.net/manual/en/function.stream-get-meta-data.php
     * @param string $key Specific metadata to retrieve.
     * @return array|mixed|null Returns an associative array if no key is
     *     provided. Returns a specific key value if a key is provided and the
     *     value is found, or null if the key is not found.
     */
    public function getMetadata($key = null)
    {
        if ($key !== null)
            return stream_get_meta_data($this->res)[$key];
        return stream_get_meta_data($this->res);
    }
}

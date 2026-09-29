<?php

namespace Phore\FileSystem;

use Phore\FileSystem\Exception\FileAccessException;
use Psr\Http\Message\StreamInterface;

class FileStream implements StreamInterface
{
    /** @var resource|null */
    protected $res;

    protected PhoreFile $file;

    /**
     * Opens a stream through the PhoreFile policy boundary.
     *
     * @param string|PhoreFile $file
     * @throws FileAccessException
     * @see PhoreFile::fopen()
     * @example new FileStream(phore_file('/tmp/demo.txt'), 'r');
     */
    public function __construct(string|PhoreFile $file, string $mode)
    {
        if (is_string($file)) {
            $file = new PhoreFile($file);
        }

        $this->file = $file;
        $this->open($mode);
    }

    public function getFileObject(): PhoreFile
    {
        return $this->file;
    }

    protected function open(string $mode): void
    {
        $path = $this->file->getFilesystemPathForOperation("fopen($mode)", true);
        $this->res = @fopen($path, $mode);

        if (!is_resource($this->res)) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new FileAccessException(
                "fopen({$this->file->getUri()}): $message"
            );
        }
    }

    public function flock(int $operation): FileStream
    {
        if (!flock($this->res, $operation)) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new FileAccessException(
                "Cannot flock('{$this->file->getUri()}'): $message"
            );
        }

        return $this;
    }

    public function datasync(): FileStream
    {
        if (!fdatasync($this->res)) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new FileAccessException(
                "Cannot fdatasync('{$this->file->getUri()}'): $message"
            );
        }

        return $this;
    }

    public function feof(): bool
    {
        return feof($this->res);
    }

    public function fwrite($data, &$bytesWritten = null): FileStream
    {
        if (false === ($bytesWritten = @fwrite($this->res, $data))) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new FileAccessException(
                "Cannot fwrite('{$this->file->getUri()}'): $message"
            );
        }

        return $this;
    }

    public function fread(int $length): string
    {
        $data = @fread($this->res, $length);
        if ($data === false) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new FileAccessException(
                "Cannot fread('{$this->file->getUri()}'): $message"
            );
        }

        return $data;
    }

    public function fgets(?int $length = null)
    {
        $data = $length === null
            ? @fgets($this->res)
            : @fgets($this->res, $length);

        if ($data === false && !feof($this->res)) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new FileAccessException(
                "Cannot fgets('{$this->file->getUri()}'): $message"
            );
        }

        return $data;
    }

    public function freadcsv(
        int $length = 0,
        string $delimiter = ',',
        string $enclosure = '"',
        string $escape_char = '\\'
    ) {
        $data = fgetcsv($this->res, $length, $delimiter, $enclosure, $escape_char);
        if ($data === false) {
            return null;
        }

        return $data;
    }

    public function fputcsv(
        array $fields,
        string $delimiter = ',',
        string $enclosure = '"',
        string $escape_char = '\\'
    ): FileStream {
        if (false === @fputcsv($this->res, $fields, $delimiter, $enclosure, $escape_char)) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new FileAccessException(
                "Cannot fputcsv('{$this->file->getUri()}'): $message"
            );
        }

        return $this;
    }

    public function isOpen(): bool
    {
        return is_resource($this->res);
    }

    public function fclose(): PhoreFile
    {
        if (!$this->isOpen()) {
            return $this->file;
        }

        if (false === @fclose($this->res)) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new FileAccessException(
                "Cannot fclose('{$this->file->getUri()}'): $message"
            );
        }

        $this->res = null;

        return $this->file;
    }

    /**
     * Exposes the raw resource only when no root-bound policy would be lost.
     *
     * @return resource|null
     */
    public function getRessource()
    {
        $this->file->assertRawResourceExportAllowed();

        return $this->res;
    }

    public function __toString()
    {
        try {
            if ($this->isSeekable()) {
                $this->seek(0);
            }

            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function seek($offset, $whence = SEEK_SET)
    {
        if (fseek($this->res, $offset, $whence) !== 0) {
            throw new \RuntimeException(
                "Cannot seek '{$this->file->getUri()}'."
            );
        }
    }

    public function fstat()
    {
        $stat = fstat($this->res);
        if ($stat === false) {
            throw new \RuntimeException(
                "Cannot stat open stream '{$this->file->getUri()}'."
            );
        }

        return $stat;
    }

    public function truncate(int $size)
    {
        if (!ftruncate($this->res, $size)) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new \RuntimeException(
                "Cannot truncate '{$this->file->getUri()}' to $size bytes: $message"
            );
        }
    }

    public function passthru(?callable $callback = null, int $chunkSize = 8192)
    {
        while (!feof($this->res)) {
            $buf = fread($this->res, $chunkSize);
            if ($buf === false) {
                throw new FileAccessException(
                    "Cannot passthru '{$this->file->getUri()}'."
                );
            }

            if ($callback !== null) {
                $callback($buf);
            } else {
                echo $buf;
            }
        }
    }

    public function close()
    {
        $this->fclose();
    }

    public function detach()
    {
        $this->file->assertRawResourceExportAllowed();
        $resource = $this->res;
        $this->res = null;

        return $resource;
    }

    public function getSize()
    {
        return $this->fstat()['size'];
    }

    public function tell()
    {
        $position = ftell($this->res);
        if ($position === false) {
            throw new \RuntimeException(
                "Cannot tell stream position for '{$this->file->getUri()}'."
            );
        }

        return $position;
    }

    public function eof()
    {
        return $this->feof();
    }

    public function isSeekable()
    {
        return (bool) ($this->getMetadata('seekable') ?? false);
    }

    public function rewind()
    {
        $this->seek(0);
    }

    public function isWritable()
    {
        $mode = (string) ($this->getMetadata('mode') ?? '');

        return strpbrk($mode, 'waxc+') !== false;
    }

    public function write($string)
    {
        $this->fwrite($string, $bytesWritten);

        return $bytesWritten;
    }

    public function isReadable()
    {
        $mode = (string) ($this->getMetadata('mode') ?? '');

        return strpbrk($mode, 'r+') !== false;
    }

    public function read($length)
    {
        return $this->fread($length);
    }

    public function getContents()
    {
        $contents = stream_get_contents($this->res);
        if ($contents === false) {
            throw new \RuntimeException(
                "Cannot read remaining contents from '{$this->file->getUri()}'."
            );
        }

        return $contents;
    }

    public function getMetadata($key = null)
    {
        $metadata = stream_get_meta_data($this->res);
        if ($key !== null) {
            return $metadata[$key] ?? null;
        }

        return $metadata;
    }
}

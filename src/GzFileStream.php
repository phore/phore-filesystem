<?php

namespace Phore\FileSystem;

use Phore\FileSystem\Exception\FileAccessException;

class GzFileStream extends FileStream
{
    protected function open(string $mode): void
    {
        $path = $this->file->getFilesystemPathForOperation("gzopen($mode)", true);
        $this->res = @gzopen($path, $mode);

        if (!is_resource($this->res)) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new FileAccessException(
                "gzopen({$this->file->getUri()}): $message"
            );
        }
    }

    public function flock(int $operation): FileStream
    {
        if (!@flock($this->res, $operation)) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new FileAccessException(
                "Cannot flock('{$this->file->getUri()}'): $message"
            );
        }

        return $this;
    }

    public function feof(): bool
    {
        return @gzeof($this->res);
    }

    public function fwrite($data, &$bytesWritten = null): FileStream
    {
        $bytesWritten = @gzwrite($this->res, $data);
        if ($bytesWritten === false) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new FileAccessException(
                "Cannot gzwrite('{$this->file->getUri()}'): $message"
            );
        }

        return $this;
    }

    public function fread(int $length): string
    {
        $data = @gzread($this->res, $length);
        if ($data === false) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new FileAccessException(
                "Cannot gzread('{$this->file->getUri()}'): $message"
            );
        }

        return $data;
    }

    public function fgets(?int $length = null)
    {
        $data = $length === null
            ? @gzgets($this->res)
            : @gzgets($this->res, $length);

        if ($data === false && !@gzeof($this->res)) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new FileAccessException(
                "Cannot gzgets('{$this->file->getUri()}'): $message"
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
        $line = $this->fgets($length === 0 ? null : $length);
        if ($line === false) {
            return null;
        }

        return str_getcsv($line, $delimiter, $enclosure, $escape_char);
    }

    public function fputcsv(
        array $fields,
        string $delimiter = ',',
        string $enclosure = '"',
        string $escape_char = '\\'
    ): FileStream {
        $line = fopen('php://temp', 'w+');
        if ($line === false) {
            throw new FileAccessException('Cannot allocate CSV formatting buffer.');
        }

        try {
            if (fputcsv($line, $fields, $delimiter, $enclosure, $escape_char) === false) {
                throw new FileAccessException('Cannot encode CSV row.');
            }
            rewind($line);
            $contents = stream_get_contents($line);
            if ($contents === false) {
                throw new FileAccessException('Cannot read encoded CSV row.');
            }
        } finally {
            fclose($line);
        }

        return $this->fwrite($contents);
    }

    public function fclose(): PhoreFile
    {
        if (!$this->isOpen()) {
            return $this->file;
        }

        if (false === @gzclose($this->res)) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new FileAccessException(
                "Cannot gzclose('{$this->file->getUri()}'): $message"
            );
        }

        $this->res = null;

        return $this->file;
    }

    public function seek($offset, $whence = SEEK_SET)
    {
        if (gzseek($this->res, $offset, $whence) !== 0) {
            throw new \RuntimeException(
                "Cannot seek gzip stream '{$this->file->getUri()}'."
            );
        }
    }

    public function getRessource()
    {
        $this->file->assertRawResourceExportAllowed();

        return $this->res;
    }

    public function getContents()
    {
        $buffer = '';
        while (!$this->feof()) {
            $buffer .= $this->fread(8192);
        }

        return $buffer;
    }

    public function getMetadata($key = null)
    {
        $metadata = ['seekable' => true, 'mode' => null];
        if ($key !== null) {
            return $metadata[$key] ?? null;
        }

        return $metadata;
    }
}

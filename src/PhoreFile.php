<?php
/**
 * Created by PhpStorm.
 * User: matthes
 * Date: 17.07.18
 * Time: 14:19
 */

namespace Phore\FileSystem;


use mysql_xdevapi\Exception;
use Phore\Core\Exception\InvalidDataException;
use Phore\FileSystem\Exception\FileAccessException;
use Phore\FileSystem\Exception\FileNotFoundException;
use Phore\FileSystem\Exception\FileParsingException;
use Phore\FileSystem\Exception\FilesystemException;
use Phore\FileSystem\Exception\PathOutOfBoundsException;
use Phore\Hydrator\Ex\InvalidStructureException;



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
            if ($this->isFile()) {
                $this->unlink();
            }
        }

    }


    public function fopen(string $mode) : FileStream
    {
        $this->validate();
        $stream = new FileStream($this, $mode);
        return $stream;
    }


    public function gzopen(string $mode) : GzFileStream
    {
        $this->validate();
        $stream = new GzFileStream($this, $mode);
        return $stream;
    }

    private function _read_content_locked ()
    {
        $this->validate();
        $file = $this->fopen("r", LOCK_SH);
        $buf = "";
        while ( ! $file->feof())
            $buf .= $file->fread(32000);
        $file->fclose();
        return $buf;
    }



    private function _write_content_locked ($content, bool $append = false)
    {
        $this->validate();
        $stream = $this->fopen("a+");
        $stream->flock(LOCK_EX);
        if ($append === false)
            $stream->truncate(0);
        $stream->fwrite($content);
        $stream->datasync();
        $stream->close();
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
     * Copy on file streaming to the other
     *
     * @param PhoreFile $target
     */
    public function streamCopyTo($destinationFile, int $maxlen=null)
    {
        $this->validate();
        $destinationFile = phore_file($destinationFile);
        $targetStream = $destinationFile->fopen("w+");

        $sourceStream = $this->fopen("r");
        while ( ! $sourceStream->feof()) {
            $targetStream->fwrite($sourceStream->fread(8012));
        }

        $sourceStream->fclose();
        $targetStream->fclose();

    }

    /**
     * @param $destinationFile
     * @return void
     * @throws \Exception
     */
    public function copyTo($destinationFile) 
    {
        $this->validate();
        $destinationFile = phore_file($destinationFile);
        $this->streamCopyTo($destinationFile);
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
        $this->validate();
        $stream = $this->fopen("r");

        // Use actual size from fstat - Important: fstat() won't rely on statcache
        $size = $stream->getSize();
        if ($size > $bytes)
            $stream->seek($size - $bytes);

        $buf = $stream->fread($bytes);

        $stream->close();
        return $buf;
    }


    /**
     * Create the full directory if not existing
     *
     * @return PhoreFile
     */
    public function createPath(int $createMask=0777) : self
    {
        $this->validate();
        phore_dir($this->getDirname())->mkdir($createMask);
        return $this;
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


    public function chmod(int $mode) : self
    {
        $this->validate();
        if ( ! chmod($this->uri, $mode))
            throw new FilesystemException("Cannot chmod $this->uri to $mode");
        return $this;
    }
    
    public function chown (string $owner) : self
    {
        $this->validate();
        if ( ! chown($this->uri, $owner))
            throw new FilesystemException("Cannot chown $this->uri to user $owner");
        return $this;
    }

    /**
     * Create the directory for this file if it does
     * not exist.
     *
     * <example>
     *  phore_file("/some/path/to/file.txt")->mkdir()->put_contents();
     * </example>
     *
     * @param $mode
     * @return $this
     */
    public function mkdir($createMask=0777) : self
    {
        $this->validate();
        $parentDir = $this->getDirname()->asDirectory();
        if ( ! $parentDir->exists())
            $parentDir->mkdir($createMask);
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

    /**
     * Get the current fileSize
     *
     * Warning: fileSize might be cached in statcache. For uncached
     * result use $file->fopen("r")->getSize()
     *
     *
     * @return int
     */
    public function fileSize () : int
    {
        $this->validate();
        return filesize($this->uri);
    }

    /**
     *
     * @template T
     * @param class-string<T>|null $cast
     * @return array|T
     * @throws FileAccessException
     * @throws FileNotFoundException
     * @throws FileParsingException
     */
    public function get_yaml(string $cast=null)
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
        if ($cast !== null) {
            if ( ! function_exists("phore_hydrate"))
                throw new \InvalidArgumentException("Package phore/hydrator is required but not installed to hydrate yaml content");

            try {
                return phore_hydrate($ret, $cast);
            } catch (\Exception $e) {
                throw new FileParsingException("Hydration of file '{$this->getUri()}' failed: {$e->getMessage()}", 0, $e);
            }
        }
        return $ret;
    }

    /**
     * Hydrate a file (json or yaml) into a Object
     *
     * Requires phore/hydrator
     *
     * @template T
     * @param class-string<T> $className
     * @param bool $strict
     * @return array|bool|float|int|object|string|null|T
     * @throws FileNotFoundException
     * @throws FileParsingException
     * @throws \Phore\Hydrator\Ex\HydratorInputDataException
     * @throws \Phore\Hydrator\Ex\InvalidStructureException
     */
    public function hydrate(string $className, bool $strict = true)
    {
        if ( ! function_exists("phore_hydrate"))
            throw new \InvalidArgumentException("Cant hydrate: phore/hydrator is not installed.");
        switch ($this->getExtension()) {
            case "json":
                $data = $this->get_json();
                break;
            case "yml":
            case "yaml":
                $data = $this->get_yaml();
                break;
            default:
                throw new \InvalidArgumentException("Can't hydrate: Unknown file extension '{$this->getExtension()}'");
        }
        try {
            return phore_hydrate($data, $className, $strict);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException("Hydration of file '{$this->getUri()}' failed: " . $e->getMessage(), 0, $e);
        }
    }


    /**
     * @template T
     * @param class-string<T>|null $cast
     *
     * @return array|T
     * @throws FileNotFoundException
     * @throws FileParsingException
     */
    public function get_json(string $cast = null) : mixed
    {
        try {
            return phore_json_decode($this->get_contents(), $cast);
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
    public function set_json(array $data, bool $prettyPrint=false) : self
    {
        $this->set_contents(phore_json_encode($data, $prettyPrint));
        return $this;
    }


    public function set_yaml(array $data) : self
    {
        $this->set_contents(phore_yaml_encode($data));
        return $this;
    }


    /**
     * Dump all data in array to csv file. (Very slow!)
     *
     * @param array $data
     * @return $this
     * @throws FileAccessException
     */
    public function set_csv(array $data) : self
    {
        $this->validate();
        $keys = [];
        foreach ($data as $val) {
            foreach ($val as $key => $val)
                $keys[$key] = true;
        }
        $keys = array_keys($keys);

        $s = $this->fopen("w");
        $s->fputcsv($keys);
        foreach ($data as $row) {
            $cur = [];
            foreach ($keys as $key) {
                if (is_object($row)) {
                    $cur[] = isset($row->$key) ? $row->$key : "";
                } else {
                    $cur[] = isset($row[$key]) ? $row[$key] : "";
                }

            }
            $s->fputcsv($cur);
        }
        $s->fclose();
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
        if ($serialize === false) {
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
        $this->validate();
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
     * Parse CSV file
     *
     * <example>
     * foreach (phore_file("some.csv")->parseCsv() as $data) {
     *      echo $data["col1"] . " ; ". $data["col2"]
     * }
     * </example>
     *
     *
     * @param array $options
     * @return \Generator
     * @throws FileAccessException
     * @throws InvalidDataException
     */
    public function parseCsv (array $options = []) : \Generator
    {
        $o = array_merge([
            "parseHeader" => true,
            "delimiter" => ",",
            "enclosure" => '"',
            "escape_char" => "\\",
            "skip_empty_lines" => true,
            "skip_invalid" => false,
            "bufSize" => 128000,
            "headerMap" => null
        ], $options);

        $s = $this->fopen("r");

        $line = 0;
        if ($o["parseHeader"] === true) {
            $line++;
            $o["headerMap"] = $s->freadcsv($o["bufSize"], $o["delimiter"], $o["enclosure"], $o["escape_char"]);
        }

        while ( ! $s->feof()) {
            $line++;
            $row = $s->freadcsv($o["bufSize"], $o["delimiter"], $o["enclosure"], $o["escape_char"]);
            if ( ! is_array($row))
                continue;
            if ($o["skip_empty_lines"] && count($row) === 1 && empty($row[0]))
                continue;
            if ($o["headerMap"] === null) {
                yield $row;
                continue;
            }
            if (count ($row) !== count($o["headerMap"])) {
                if ($o["skip_invalid"])
                    continue;
                throw new InvalidDataException("Invalid csv data in '$this' on line $line: Expected " . count($o["headerMap"]) . " columns: '" . implode($o["delimiter"], $row) . "'");
            }

            $ret = [];
            foreach ($row as $idx => $val)
                $ret[$o["headerMap"][$idx]] = $val;
            yield $ret;
        }
        $s->fclose();
    }

    /**
     * Create a new tempoary file with the gunziped
     * contents.
     *
     * <example>
     *  phore_file("file.gz")->gunzip()->get_contents();
     * </example>
     *
     * @return PhoreTempFile
     */
    public function gunzip () : PhoreTempFile
    {
        $this->validate();
        $tmp = phore_tempfile();
        $tmpWriter = $tmp->fopen("w+");
        $inFileStream = $this->gzopen("r");

        while ( ! $inFileStream->feof()) {
            $tmpWriter->fwrite($inFileStream->fread(8012));
        }
        $tmpWriter->fclose();
        $inFileStream->fclose();
        return $tmp;
    }


    /**
     * @param string $mode
     * @return PhoreFile
     */
    public function touch(int $mode=0777) : self
    {
        $this->validate();
        if ( ! file_exists($this->uri)) {
            if ( ! file_exists(dirname($this->uri)))
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
        $this->validate();
        return filesize($this->uri);
    }


    public function rename ($newName) : self
    {
        $this->validate($newName);
        if ( ! @rename($this->uri, $newName))
            throw new FileAccessException("Cannot rename file '{$this->uri}' to '{$newName}': " . implode(" ", error_get_last()));
        $this->filename = $newName;
        return $this;
    }

    public function unlink() : self
    {
        $this->validate();
        if ( ! @unlink($this->uri))
            throw new FileAccessException("Cannot unlink file '{$this->uri}': " . implode(" ", error_get_last()));
        return $this;
    }

}

<?php

declare(strict_types=1);
/**
 * Created by PhpStorm.
 * User: matthes
 * Date: 17.07.18
 * Time: 14:19
 */

namespace Phore\FileSystem;


use Phore\Core\Exception\InvalidDataException;
use Phore\Core\Exception\YamlDecodeException;
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
        $file = $this->fopen("r");
        $file->flock(LOCK_SH);
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
        if (!$this->exists()) {
            throw new FileNotFoundException("File '{$this->getUri()}' not found.");
        }

        return $this->_read_content_locked();
    }

    /**
     * @return array
     * @throws FileAccessException
     * @throws FileNotFoundException
     */
    public function get_contents_array() : array {
        return explode("\n", $this->get_contents());
    }


    /**
     * Copy on file streaming to the other
     *
     * @param PhoreFile $target
     */
    public function streamCopyTo($destinationFile, ?int $maxlen=null)
    {
        $this->validate();
        $destinationFile = $this->resolveTargetFile($destinationFile);
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
    public function copyTo($destinationFile, bool $mkdir = true)
    {
        $this->validate();
        $destinationFile = $this->resolveTargetFile($destinationFile);
        $destinationFile->getDirname()->assertDirectory($mkdir);
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
        $this->getDirname()->asDirectory()->mkdir($createMask);
        return $this;
    }



    public function set_contents(string $contents): self
    {
        $this->_write_content_locked($contents);

        return $this;
    }


    public function chmod(int $mode): self
    {
        $path = $this->getFilesystemPathForOperation('chmod', false);
        if (!@chmod($path, $mode)) {
            throw new FilesystemException("Cannot chmod '{$this->getUri()}' to $mode.");
        }

        return $this;
    }

    public function chown(string $owner): self
    {
        $path = $this->getFilesystemPathForOperation('chown', false);
        if (!@chown($path, $owner)) {
            throw new FilesystemException("Cannot chown '{$this->getUri()}' to user '$owner'.");
        }

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
    public function append_content(string $appendContent): self
    {
        $this->_write_content_locked($appendContent, true);

        return $this;
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
        return filesize($this->getFilesystemPathForOperation('filesize', false));
    }

    private function assertSafeYamlInput(string $yaml): void
    {
        if (preg_match('/!php\\/object\\b/i', $yaml)) {
            throw new FileParsingException(
                "Unsafe PHP object YAML tag in file '{$this->getUri()}'."
            );
        }
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
    public function get_yaml(?string $cast=null)
    {
        $textData = $this->get_contents();
        $this->assertSafeYamlInput($textData);

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
    public function get_json(?string $cast = null) : mixed
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
    public function set_json(array|object $data, bool $prettyPrint=false) : self
    {
        $this->set_contents(phore_json_encode(phore_object_to_array($data), $prettyPrint));
        return $this;
    }


    public function set_yaml(array|object $data) : self
    {
        $this->set_contents(phore_yaml_encode(phore_object_to_array($data)));
        return $this;
    }


    /**
     * Read a Jekyll-style front matter file.
     *
     * @template T of object
     * @param class-string<T>|null $cast
     * @return FrontMatterFile<array|T>
     * @throws FileNotFoundException
     * @throws FileParsingException
     */
    public function get_front_matter(?string $cast = null, bool $required = true): ?FrontMatterFile
    {
        $contents = $this->get_contents();
        $filename = $this->getUri();

        if (!preg_match('/\A---\r?\n/', $contents)) {
            if (!$required) {
                return null;
            }

            throw new FileParsingException(
                "Front matter parsing of file '{$filename}' failed on line 1: Expected opening delimiter '---'."
            );
        }

        if ( ! preg_match(
            '/\A---\r?\n(?<header>.*?)(?<delimiter>^---[ \t]*\r?$(?:\n|\z))(?<content>.*)\z/ms',
            $contents,
            $matches
        )) {
            $line = substr_count($contents, "\n") + 1;
            throw new FileParsingException(
                "Front matter parsing of file '{$filename}' failed on line {$line}: Expected closing delimiter '---'."
            );
        }

        $yaml = $matches['header'];
        if (str_ends_with($yaml, "\r\n")) {
            $yaml = substr($yaml, 0, -2);
        } elseif (str_ends_with($yaml, "\n")) {
            $yaml = substr($yaml, 0, -1);
        }

        $this->assertSafeYamlInput($yaml);

        try {
            $header = trim($yaml) === '' ? [] : phore_yaml_decode($yaml);
        } catch (YamlDecodeException $e) {
            $fileLine = ($e->getErrorLine() ?? 1) + 1;
            $location = "line {$fileLine}";
            if ($e->getErrorColumn() !== null) {
                $location .= ", column {$e->getErrorColumn()}";
            }
            throw new FileParsingException(
                "Front matter YAML parsing of file '{$filename}' failed on {$location}: {$e->getMessage()}",
                0,
                $e
            );
        } catch (\InvalidArgumentException $e) {
            throw new FileParsingException(
                "Front matter YAML parsing of file '{$filename}' failed on line 2: {$e->getMessage()}",
                0,
                $e
            );
        }

        if ($cast !== null) {
            if ( ! function_exists('phore_hydrate')) {
                throw new \InvalidArgumentException(
                    'Package phore/hydrator is required but not installed to hydrate front matter'
                );
            }

            try {
                $header = phore_hydrate($header, $cast);
            } catch (\Exception $e) {
                throw new FileParsingException(
                    "Hydration of front matter in file '{$filename}' failed: {$e->getMessage()}",
                    0,
                    $e
                );
            }
        }

        return new FrontMatterFile($filename, $header, $matches['content'], $contents);
    }


    /**
     * Write a Jekyll-style front matter file.
     */
    public function put_front_matter(FrontMatterFile $frontMatterFile): self
    {
        return $this->set_contents($frontMatterFile->render());
    }


    /**
     * Dump all data in array to csv file. (Very slow!)
     *
     * @param array $data
     * @param array|null $columns   Provide the columns to output. Us a map to rename the column Name. If null all columns will be outputted
     * @return $this
     * @throws FileAccessException
     */
    public function set_csv(array $data, ?array $columns = null) : self
    {
        $this->validate();
        $keys = [];
        $columnNames = [];
        if ($columns === null) {
            foreach ($data as $val) {
                foreach ($val as $key => $val)
                    $keys[$key] = true;
            }
            $keys = array_keys($keys);
            $columnNames = $keys;
        } else {
            foreach ($columns as $key => $value) {
                if (is_int($key)) {
                    $keys[] = $value;
                    $columnNames[] = $value;
                    continue;
                }
                $keys[] = $key;
                $columnNames[] = $value;
            }
        }



        $s = $this->fopen("w");
        $s->fputcsv($columnNames);
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
            "skip_invalid" => false,        // Skip lines with more or less columns than header
            "strict" => true,               // Compare the number of columns with the header (if off it will try to parse as much as possible)
            "skip_start_char" => null,      // Skip lines starting with this char (e.g. "#")
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

            if ($o["skip_start_char"] !== null && str_starts_with($row[0] ?? $o["skip_start_char"], $o["skip_start_char"]))
                continue;

            if ( ! is_array($row))
                continue;
            if ($o["skip_empty_lines"] && count($row) === 1 && empty($row[0]))
                continue;
            if ($o["headerMap"] === null) {
                yield $row;
                continue;
            }
            if (count ($row) !== count($o["headerMap"]) && $o["strict"]) {
                if ($o["skip_invalid"])
                    continue;
                throw new InvalidDataException("Invalid csv data in '$this' on line $line: Expected " . count($o["headerMap"]) . " columns: '" . implode($o["delimiter"], $row) . "' (strict mode on)");
            }

            $ret = [];
            foreach ($row as $idx => $val) {
                if (!isset($o["headerMap"][$idx]))
                    continue;
                $ret[$o["headerMap"][$idx]] = $val;
            }
            yield $ret;
        }
        $s->fclose();
    }


    /**
     * Return array of ColumnName => Value
     *
     * <example>
     *    $data = phore_file("some.csv")->get_csv();
     *    foreach ($data as $row) {
     *       echo $row["col1"] . " ; ". $row["col2"]
     *   }
     * </example>
     *
     * @param array $options
     * @return array
     * @throws FileAccessException
     * @throws InvalidDataException
     */
    public function get_csv(array $options = []) : array {
        $ret = [];
        foreach ($this->parseCsv($options) as $row) {
            $ret[] = $row;
        }
        return $ret;
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
    public function touch(int $mode = 0777): self
    {
        $path = $this->getFilesystemPathForOperation('touch', true);
        if (!file_exists($path)) {
            $this->getDirname()->asDirectory()->mkdir($mode);
            if (!@touch($path)) {
                $message = error_get_last()['message'] ?? 'unknown error';
                throw new FilesystemException(
                    "Cannot touch file '{$this->getUri()}': $message"
                );
            }
        }

        $this->getFilesystemPathForOperation('touch result', false);
        if (!is_file($path)) {
            throw new FilesystemException(
                "touch file '{$this->getUri()}': Uri exists but is not a file."
            );
        }

        return $this;
    }


    public function getFilesize(): int
    {
        return filesize($this->getFilesystemPathForOperation('getFilesize', false));
    }


    public function rename($newName): self
    {
        if (!is_string($newName) && !$newName instanceof PhoreUri) {
            throw new \InvalidArgumentException('rename target must be string or PhoreUri.');
        }

        $target = $this->resolveTargetFile($newName);
        $sourcePath = $this->getFilesystemPathForOperation('rename source', false);
        $targetPath = $target->getFilesystemPathForOperation('rename target', true);

        if (!@rename($sourcePath, $targetPath)) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new FileAccessException(
                "Cannot rename file '{$this->getUri()}' to '{$target->getUri()}': $message"
            );
        }

        $this->adoptPath($target);

        return $this;
    }

    public function unlink(): self
    {
        $path = $this->getFilesystemPathForOperation('unlink', false);
        if (!@unlink($path)) {
            $message = error_get_last()['message'] ?? 'unknown error';
            throw new FileAccessException(
                "Cannot unlink file '{$this->getUri()}': $message"
            );
        }

        return $this;
    }

}

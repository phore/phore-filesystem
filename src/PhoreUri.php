<?php
/**
 * Created by PhpStorm.
 * User: matthes
 * Date: 17.07.18
 * Time: 14:04
 */

namespace Phore\FileSystem;


use Phore\FileSystem\Exception\FileAccessException;
use Phore\FileSystem\Exception\FileNotFoundException;
use Phore\FileSystem\Exception\FilesystemException;
use Phore\FileSystem\Exception\PathOutOfBoundsException;

class PhoreUri
{

    protected $uri;

    /**
     * The Relative path
     *
     * Only set if this path was generatet by genWalk() or by calling withSubPath()
     *
     * Will contain the full name without leading slash
     *
     * @var string[]|null
     */
    protected $relPath = null;

    public function __construct(string $uri, array $__relPath=null)
    {
        $this->uri = $uri;
        $this->validate();
        $this->relPath = $__relPath;
    }


    public function validate(string $optFileName = null)
    {
        if (strpos($this->uri, "\0") !== false) {
            throw new \Exception("Null-byte character detected in uri.");
        }
        if ($optFileName !== null && strpos($optFileName, "\0") !== false) {
            throw new \Exception("Null-byte character detected in uri.(parameter 1)");
        }
    }


    public function getRelPath() : ?string
    {
        if ($this->relPath === null)
            return null;
        return implode("/", $this->relPath);
    }

    /**
     * some/path/demo.inc.txt => some/path
     *
     * @return PhoreUri
     */
    public function getDirname () : self
    {
        return new self(dirname($this->uri));
    }


    /**
     * demo.inc.txt => demo.inc.txt
     *
     * @param string|null $suffix
     * @return string
     */
    public function getBasename(string $suffix=null) : string
    {
        return basename($this->uri, $suffix);
    }

    /**
     * demo.inc.txt => txt
     *
     * @return string
     */
    public function getExtension() : string
    {
        return pathinfo($this->uri, PATHINFO_EXTENSION);
    }

    /**
     *
     * demo.inc.txt => demo.inc
     *
     * @return string
     */
    public function getFilename () : string
    {
        return pathinfo($this->uri, PATHINFO_FILENAME);
    }

    public function withDirName() : PhoreDirectory
    {
        return new PhoreDirectory(dirname($this->uri));
    }


    public function withSubPath (string $subpath) : PhoreUri
    {
        $parts = explode("/", $subpath);
        $ret = [];

        $relPath = $this->relPath;
        if ($relPath === null)
            $relPath = [];

        foreach ($parts as $part) {
            if ($part == "")
                continue;
            if ($part == "." || $part == "")
                continue;
            if ($part == "..") {
                if (count ($ret) == 0)
                    throw new PathOutOfBoundsException("SubPath is out of bounds: $subpath");
                array_pop($ret);
                continue;
            }
            $ret[] = $part;
            $relPath[] = $part;
        }
        $startUri = $this->uri;
        if ($this instanceof PhoreFile) {
            $startUri = dirname($startUri);
        }

        return new PhoreUri($startUri .= "/" . implode("/", $ret), $relPath);
    }

    public function withRelativePath (string $relpath) : PhoreUri
    {
        $startUri = $this->uri;
        if ($this instanceof PhoreFile) {
            $startUri = dirname($startUri);
        }
        $prefix = "";
        if (substr($startUri, 0, 1) === "/")
            $prefix = "/"; // Absolute path

        $parts = explode("/", $startUri . "/"  . $relpath);
        $ret = [];
        foreach ($parts as $part) {
            if ($part == "")
                continue;
            if ($part == "." || $part == "")
                continue;
            if ($part == "..") {
                if (count ($ret) == 0)
                    throw new PathOutOfBoundsException("SubPath is out of bounds: $relpath");
                array_pop($ret);
                continue;
            }
            $ret[] = $part;
        }

        return new PhoreUri($prefix . implode("/", $ret), $ret);
    }


    /**
     * Validate uri against fnmatch() pattern
     *
     * Parameter 1 can be string or array of patterns.
     *
     * Returns true if any of the patterns match - otherwise false is returned
     *
     * <example>
     *   phore_uri()->fnmatch("*.php")
     *   phore_uri()->fnmatch(["*.php", "*.js"]);
     * </example>
     *
     * @param string|string[] $patterns
     * @param int $flags
     * @return bool
     */
    public function fnmatch ($patterns, int $flags=0) : bool
    {
        if ( ! is_array($patterns))
            $patterns = [ $patterns ];
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, (string)$this, $flags))
                return true;
        }
        return false;
    }


    public function isDirectory () : bool
    {
        return file_exists($this->uri) && is_dir($this->uri);
    }

    public function isFile () : bool
    {
        return file_exists($this->uri) && is_file($this->uri);
    }

    public function exists() : bool
    {
        return file_exists($this->uri);
    }




    /**
     * Returns true, if the path is a subpath of the path specified in parameter 1
     *
     * <example>
     *  assert(phore_uri("/some/path")->isSubpathOf("/some") === true)
     * </example>
     *
     * @param $path
     * @return bool
     */
    public function isSubpathOf($path) : bool
    {
        return startsWith((string)$this, (string)$path);
    }


    public function assertDirectory (bool $createIfNotExisting=false) : PhoreDirectory
    {
        $this->validate();
        if ($createIfNotExisting === true && ! file_exists($this->uri)) {
            if (!mkdir($concurrentDirectory = $this->uri, 0777, true) && !is_dir($concurrentDirectory)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
            }
        }
        if (file_exists($this->uri) && is_dir($this->uri))
            return new PhoreDirectory($this->uri, $this->relPath);
        throw new FilesystemException("Uri '$this->uri' is not a valid directory.");
    }



    public function assertFile () : PhoreFile
    {
        $this->validate();
        if (file_exists($this->uri) && is_file($this->uri))
            return new PhoreFile($this->uri, $this->relPath);
        throw new FilesystemException("Uri '$this->uri' is not a valid file.");
    }

    public function assertReadable () : self
    {
        $this->validate();
        if ( ! is_readable($this->uri))
            throw new FileAccessException("Uri '$this->uri' is not readable");
        return $this;
    }

    public function assertWritable () : self
    {
        $this->validate();
        if ( ! is_writable($this->uri))
            throw new FileAccessException("Uri '$this->uri' is not writable");
        return $this;
    }


    public function getUri() : string
    {
        $this->validate();
        return $this->uri;
    }


    public function __toString()
    {
        $this->validate();
        return $this->uri;
    }

    public function asFile() : PhoreFile
    {
        $this->validate();
        return new PhoreFile($this->uri, $this->relPath);
    }


    /**
     * Join path
     *
     * <example>
     *  phore_uri("/some/path/").join("sub", "file.txt") === "/some/path/sub/file.txt"
     * </example>
     *
     * @param mixed ...$elements
     * @return PhoreUri
     */
    public function join(...$elements) : PhoreUri
    {
        $newUri = $this->uri;
        if (endsWith($newUri, "/"))
            $newUri = substr($newUri, 0, -1);
        foreach ($elements as $element) {
            if (startsWith($element, "/"))
                $element = substr($element, 1);
            $newUri .= "/$element";
        }
        return new PhoreUri($newUri);
    }


    /**
     * Securely join a path with each element as
     * a directory.
     *
     * @param ...$elements
     * @return PhoreUri
     */
    public function join_secure(...$elements) : PhoreUri
    {
        $path = $this;
        foreach ($elements as $element) {
            if ($element === "." || $element === "..")
                throw new \InvalidArgumentException("Path security violation: path must not contain '.' or '..'");

            $element = urlencode($element);
            if (strlen($element) === 0)
                throw new \InvalidArgumentException("Path must not contain empty string element");
            $path = $path->join($element);
        }
        return $path;
    }

    /**
     * Transform to absolute path
     *
     * The optional parameter can be used to specify a dedicated root directory.
     * If empty getcwd() will be used.
     *
     * <example>
     *  phore_uri("relative/path/to/file")->abs("/root/dir") === "/root/dir/relative/path/to/file"
     *  phore_uri("/absolute/path")->abs("/root/dir") === "/absolute/path"
     * </example>
     *
     * @param null $cwd
     * @return PhoreUri
     */
    public function abs(string $cwd=null) : PhoreUri
    {
        if ($cwd === null)
            $cwd = getcwd();
        $newUri = $this->uri;
        if ( ! startsWith($newUri, "/")) {
            if (endsWith($cwd, "/"))
                $cwd = substr($cwd, 0, -1);
            $newUri = $cwd . "/" . $newUri;
        }
        return new PhoreUri($newUri);
    }

    /**
     * Make a absolute path relative to the path provided in parameter 1
     *
     * If the path is already relative, return it. If it is not a subpath
     * of the path throw error
     *
     * <example>
     *  phore_uri("/some/absolute/path")->rel("/some") === "absolute/path"
     *  phore_uri("reatlive/path")->rel("/some") === "relative/path"
     * </example>
     *
     * @param string $rootPath
     * @return PhoreUri
     */
    public function rel(string $rootPath) : PhoreUri
    {
        if ( ! startsWith($this->uri, "/"))
            return new PhoreUri($this->uri);
        if ( ! startsWith($this->uri, $rootPath))
            throw new \InvalidArgumentException("Path '$this->uri' is not a subpath of '$rootPath'");
        $newUri = substr($this->uri, strlen($rootPath));
        if (startsWith($newUri, "/"))
            $newUri = substr($newUri, 1);
        return new PhoreUri($newUri);
    }


    public function withFileName(string $filename, string $fileExtension="") : PhoreFile
    {
        if ($fileExtension !== "" && ! ctype_alnum($fileExtension))
            throw new \InvalidArgumentException("File extension '$fileExtension' must not contain special chars.");
        if ($fileExtension !== "")
            $fileExtension = "." . $fileExtension;

        return new PhoreFile($this->uri . "/" . addslashes($filename) . $fileExtension);
    }


    public function withParentDir() : PhoreDirectory
    {
        $newUri = dirname($this->uri);
        if ($newUri === ".")
            $newUri = "/";
        return new PhoreDirectory($newUri);
    }


    public function asDirectory() : PhoreDirectory
    {
        return new PhoreDirectory($this->uri, $this->relPath);
    }
}

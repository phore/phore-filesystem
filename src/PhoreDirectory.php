<?php
/**
 * Created by PhpStorm.
 * User: matthes
 * Date: 17.07.18
 * Time: 17:57
 */

namespace Phore\FileSystem;


use Phore\FileSystem\Exception\FileAccessException;
use Phore\FileSystem\Exception\FileNotFoundException;
use Phore\FileSystem\Exception\FilesystemException;

class PhoreDirectory extends PhoreUri
{


    public function mkdir($createMask=0777) : self
    {
        $this->validate();
        if ( ! is_dir($this->uri)) {
            if (!mkdir($concurrentDirectory = $this->uri, $createMask, true) && !is_dir($concurrentDirectory)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
            }
        }
        return $this;
    }

    private function _rmDirRecursive(string $dir)
    {
        if ( ! is_dir($dir))
            return false;
        $files = array_diff(scandir($dir), array('.','..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->_rmDirRecursive("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    public function rmDir($recursive=false) : self
    {
        $this->validate();
        if ( ! is_dir($this->uri))
            return $this;

        if ($recursive === true) {
            $this->_rmDirRecursive((string)$this);
        } else {
            if ( ! rmdir((string)$this))
                throw new FilesystemException("Cannot rmdir $this->uri");
        }
        return $this;
    }

    public function chown ($owner) : self
    {
        $this->validate();
        if ( ! chown($this->uri, $owner))
            throw new FilesystemException("Cannot chown $this->uri to user $owner");
        return $this;
    }


    public function walk(callable $fn, string $filter=null) : bool
    {
        $this->validate();
        $dirFp = opendir($this->uri);
        if (!$dirFp)
            throw new FileAccessException("Cannot open path '{$this->uri}' for indexing.");
        while (($curSub = readdir($dirFp)) !== false) {
            if ($curSub == "." || $curSub == "..")
                continue;

            if ($filter !== null) {
                if ( ! fnmatch($filter, $curSub)) {
                    continue;
                }
            }

            $path = $this->withSubPath($curSub);
            if ($path->isFile())
                $path = $path->asFile();

            $ret = $fn($path);
            if ($ret === false) {
                closedir($dirFp);
                return false;
            }
        }
        closedir($dirFp);
        return true;
    }

    public function walkR(callable $fn, string $filter=null) : bool
    {
        $this->validate();
        return $this->walk(function (PhoreUri $uri) use ($fn, $filter) {
            if ($uri->isDirectory()) {
                return $uri->asDirectory()->walkR($fn, $filter);
            }
            return $fn($uri);
        }, $filter);
    }


    /**
     * @param string|null $filter
     * @return \Iterator|PhoreUri[]
     * @throws Exception\PathOutOfBoundsException
     * @throws FileAccessException
     */
    public function genWalk(string $filter = null, bool $recursive = false) : \Iterator
    {
        $this->validate();
        $dirFp = opendir($this->uri);
        if (!$dirFp)
            throw new FileAccessException("Cannot open path '{$this->uri}' for indexing.");
        while (($curSub = readdir($dirFp)) !== false) {
            if ($curSub == "." || $curSub == "..")
                continue;

            $path = $this->withSubPath($curSub);
            if ($path->isDirectory() && $recursive === true) {
                $path = $path->assertDirectory();


                foreach ($path->genWalk($filter, $recursive) as $subPath) {
                    yield $subPath;
                }
            }

            if ($filter !== null) {
                if ( ! fnmatch($filter, $curSub)) {
                    continue;
                }
            }

            if ($path->isFile())
                $path = $path->asFile();
            yield $path;
        }
        closedir($dirFp);
    }



    /**
     * @return PhoreUri[]
     * @throws FileAccessException
     */
    public function getListSorted(string $filter=null, bool $recursive = false) : array
    {
        $this->validate();
        $ret = [];
        foreach ($this->genWalk($filter, $recursive) as $path) {
            $ret[] = $path;
        }
        usort($ret, function (PhoreUri $a, PhoreUri $b) {
            if ((string)$a == (string)$b)
                return 0;
            return ((string)$a < (string)$b) ? -1 : 1;
        });
        return $ret;
    }

    /**
     * Import file contents of parameter 1 to this directory
     *
     * @param $filename
     */
    public function importZipFile($filename)
    {
        $this->validate();
        $this->validate($filename);
        phore_exec("unzip :zipfile -d :folder", ["zipfile" => $filename, "folder" => (string)$this]);
    }

    /**
     * Find a single file in the directory. Return the PhoreFile Object
     * if found, thorws FileNotFoundException if not.
     *
     * If parameter 2 is specified, it will contain the machtes from preg_match()
     *
     * <example>
     * phore_dir("/tmp")->getFileByPattern("/^some[0-9]\.txt$/")->get_contents();
     * </example>
     *
     * @param string $regex
     * @param array $matches
     * @return PhoreFile
     * @throws FileNotFoundException
     */
    public function getFileByPattern(string $regex, &$matches = null) : PhoreFile
    {
        $this->validate();
        $found = null;
        $this->walkR(function (PhoreUri $uri) use ($regex, &$found, &$matches) {
            if (preg_match($regex, (string)$uri, $matches) && $uri->isFile()) {
                $found = $uri;
                return false;
            }
        });
        if ($found === null)
            throw new FileNotFoundException("No file matching pattern '$regex' found in directory '$this'");
        return $found->asFile();
    }

}

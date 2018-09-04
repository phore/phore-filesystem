<?php
/**
 * Created by PhpStorm.
 * User: matthes
 * Date: 17.07.18
 * Time: 17:57
 */

namespace Phore\FileSystem;


use Phore\FileSystem\Exception\FileAccessException;
use Phore\FileSystem\Exception\FilesystemException;

class PhoreDirectory extends PhoreUri
{


    public function mkdir($createMask=0777) : self
    {
        if ( ! $this->isDirectory())
            mkdir($this->uri, $createMask, true);
        return $this;
    }


    public function chown ($owner) : self
    {
        if ( ! chown($this->uri, $owner))
            throw new FilesystemException("Cannot chown $this->uri to user $owner");
        return $this;
    }


    public function walk(callable $fn, string $filter=null) : bool
    {
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
        return $this->walk(function (PhoreUri $uri) use ($fn, $filter) {
            if ($uri->isDirectory()) {
                return $uri->asDirectory()->walkR($fn, $filter);
            }
            return $fn($uri);
        }, $filter);
    }


    /**
     * @return PhoreUri[]
     * @throws FileAccessException
     */
    public function getListSorted(string $filter=null) : array
    {
        $ret = [];
        $this->walk(function(PhoreUri $uri) use (&$ret) {
            $ret[] = $uri;
        }, $filter);
        usort($ret, function (PhoreUri $a, PhoreUri $b) {
            if ((string)$a == (string)$b)
                return 0;
            return ((string)$a < (string)$b) ? -1 : 1;
        });
        return $ret;
    }

}

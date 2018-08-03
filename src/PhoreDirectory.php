<?php
/**
 * Created by PhpStorm.
 * User: matthes
 * Date: 17.07.18
 * Time: 17:57
 */

namespace Phore\FileSystem;


use Phore\FileSystem\Exception\FileAccessException;

class PhoreDirectory extends PhoreUri
{


    public function mkdir($createMask=0777) : self
    {
        mkdir($this->uri, $createMask, true);
        return $this;
    }


    public function walk(callable $fn) : bool
    {
        $dir = opendir($this->path);
        if (!$dir)
            throw new FileAccessException("Cannot open path '$this' for indexing.");
        while (($curSub = readdir($dir)) !== false) {
            if ($curSub == "." || $curSub == "..")
                continue;

            $path = $this->withSubPath($dir);
            if ($path->isFile())
                $path = $path->asFile();

            $ret = $fn($path);
            if ($ret === false) {
                closedir($dir);
                return false;
            }
        }
        closedir($dir);
        return true;
    }

    public function walkR(callable $fn) : bool
    {
        return $this->walk(function (PhoreUri $uri) use ($fn) {
            if ($uri->isDirectory()) {
                return $uri->asDirectory()->walkR($fn);
            }
            return $fn($uri);
        });
    }


    /**
     * @return PhoreUri[]
     * @throws FileAccessException
     */
    public function getListSorted() : array
    {
        $ret = [];
        $this->walk(function(PhoreUri $uri) use (&$ret) {
            $ret[] = $uri;
        });
        sort($ret, function (PhoreUri $a, PhoreUri $b) {
            if ((string)$a == (string)$b)
                return 0;
            return ((string)$a < (string)$b) ? -1 : 1;
        });
        return $ret;
    }

}

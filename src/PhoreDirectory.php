<?php
/**
 * Created by PhpStorm.
 * User: matthes
 * Date: 17.07.18
 * Time: 17:57
 */

namespace Phore\FileSystem;


class PhoreDirectory extends PhoreUri
{


    public function mkdir($createMask=0777) : self
    {
        mkdir($this->uri, $createMask, true);
        return $this;
    }


}
<?php


namespace Phore\FileSystem;


class PhoreTempDir extends PhoreDirectory
{

    public function __construct()
    {
        $uri = sys_get_temp_dir() . "/" . uniqid("ptd_");
        parent::__construct($uri);
        $this->mkdir();
    }


    

    public function __destruct()
    {
        $this->rmDir(true);
    }

}

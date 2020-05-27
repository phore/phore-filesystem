<?php


namespace Phore\FileSystem;


class PhoreTempDir extends PhoreDirectory
{

    private $autoremove;
    
    public function __construct(bool $autoremove = true)
    {
        $this->autoremove = $autoremove;
        $uri = sys_get_temp_dir() . "/" . uniqid("ptd_");
        parent::__construct($uri);
        $this->mkdir();
    }


    

    public function __destruct()
    {
        if ($this->autoremove)
            $this->rmDir(true);
    }

}

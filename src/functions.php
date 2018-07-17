<?php
/**
 * Created by PhpStorm.
 * User: matthes
 * Date: 17.07.18
 * Time: 15:16
 */

function phore_path(string $path) : \Phore\FileSystem\PhoreUri
{
    return new \Phore\FileSystem\PhoreUri($path);
}
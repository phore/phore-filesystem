<?php
/**
 * Created by PhpStorm.
 * User: matthes
 * Date: 17.07.18
 * Time: 15:16
 */

function phore_uri($uri) : \Phore\FileSystem\PhoreUri
{
    return new \Phore\FileSystem\PhoreUri($uri);
}

function phore_file($filename) : \Phore\FileSystem\PhoreFile
{
    return new \Phore\FileSystem\PhoreFile($filename);
}

function phore_tempfile() : \Phore\FileSystem\PhoreTempFile
{
    return new \Phore\FileSystem\PhoreTempFile();
}

function phore_dir($directory) : \Phore\FileSystem\PhoreDirectory
{
    return new \Phore\FileSystem\PhoreDirectory($directory);
}

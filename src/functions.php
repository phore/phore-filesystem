<?php
/**
 * Created by PhpStorm.
 * User: matthes
 * Date: 17.07.18
 * Time: 15:16
 */

function phore_uri($uri) : \Phore\FileSystem\PhoreUri
{
    if ($uri instanceof \Phore\FileSystem\PhoreUri)
        return $uri;
    return new \Phore\FileSystem\PhoreUri($uri);
}

function phore_file($filename) : \Phore\FileSystem\PhoreFile
{
    if ($filename instanceof \Phore\FileSystem\PhoreFile)
        return $filename;
    return new \Phore\FileSystem\PhoreFile($filename);
}

function phore_tempfile() : \Phore\FileSystem\PhoreTempFile
{
    return new \Phore\FileSystem\PhoreTempFile();
}

function phore_dir($directory) : \Phore\FileSystem\PhoreDirectory
{
    if ($directory instanceof \Phore\FileSystem\PhoreDirectory)
        return $directory;
    return new \Phore\FileSystem\PhoreDirectory($directory);
}
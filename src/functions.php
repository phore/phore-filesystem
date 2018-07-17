<?php
/**
 * Created by PhpStorm.
 * User: matthes
 * Date: 17.07.18
 * Time: 15:16
 */

function phore_uri(string $uri) : \Phore\FileSystem\PhoreUri
{
    return new \Phore\FileSystem\PhoreUri($uri);
}
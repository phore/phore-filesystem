<?php

namespace Phore\FileSystem;

/**
 * @template THeader of array|object
 */
class FrontMatterFile
{
    /**
     * @param THeader $header
     */
    public function __construct(
        public ?string $filename = null,
        public array|object $header = [],
        public string $content = ""
    ) {
    }
}

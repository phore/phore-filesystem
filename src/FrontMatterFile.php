<?php

namespace Phore\FileSystem;

/**
 * @template THeader of array|object
 */
class FrontMatterFile
{
    private string $originalHeaderSnapshot;
    private string $originalContent;

    /**
     * @param THeader $header
     */
    public function __construct(
        public ?string $filename = null,
        public array|object $header = [],
        public string $content = '',
        public ?string $sourceContents = null
    ) {
        $this->originalHeaderSnapshot = serialize(phore_object_to_array($this->header));
        $this->originalContent = $this->content;
    }

    /**
     * Renders the current front matter document without writing it.
     *
     * If header and body are unchanged and sourceContents is available, the
     * exact original bytes are returned. Modified documents are serialized
     * using the same YAML representation as put_front_matter().
     *
     * @see PhoreFile::put_front_matter()
     * @example $contents = $frontMatter->render();
     */
    public function render(): string
    {
        $currentHeader = phore_object_to_array($this->header);
        if (
            $this->sourceContents !== null
            && serialize($currentHeader) === $this->originalHeaderSnapshot
            && $this->content === $this->originalContent
        ) {
            return $this->sourceContents;
        }

        $yaml = $currentHeader === []
            ? ''
            : rtrim(phore_yaml_encode($currentHeader), "\r\n");

        $contents = "---\n";
        if ($yaml !== '') {
            $contents .= $yaml . "\n";
        }

        return $contents . "---\n" . $this->content;
    }
}

<?php

namespace Test;

use Phore\Core\Exception\YamlDecodeException;
use Phore\FileSystem\Exception\FileParsingException;
use Phore\FileSystem\FrontMatterFile;
use Phore\FileSystem\PhoreTempFile;
use PHPUnit\Framework\TestCase;

class FrontMatterFileTest extends TestCase
{
    public function testReadsFrontMatterAndKeepsDelimiterInContent(): void
    {
        $file = new PhoreTempFile();
        $file->set_contents("---\ntitle: Example\n\ntags:\n  - php\n---\nFirst\n---\nLast\n");

        $frontMatter = $file->get_front_matter();

        $this->assertSame($file->getUri(), $frontMatter->filename);
        $this->assertSame('Example', $frontMatter->header['title']);
        $this->assertSame(['php'], $frontMatter->header['tags']);
        $this->assertSame("First\n---\nLast\n", $frontMatter->content);
    }

    public function testReadsEmptyHeaderAndCrLf(): void
    {
        $file = new PhoreTempFile();
        $file->set_contents("---\r\n\r\n---\r\nContent\r\n");

        $frontMatter = $file->get_front_matter();

        $this->assertSame([], $frontMatter->header);
        $this->assertSame("Content\r\n", $frontMatter->content);
    }

    public function testReadsClosingDelimiterAtEndOfFile(): void
    {
        $file = new PhoreTempFile();
        $file->set_contents("---\ntitle: \"---\"\n---");

        $frontMatter = $file->get_front_matter();

        $this->assertSame('---', $frontMatter->header['title']);
        $this->assertSame('', $frontMatter->content);
    }

    public function testWritesAndReadsFrontMatterRoundTrip(): void
    {
        $file = new PhoreTempFile();
        $input = new FrontMatterFile(null, ['title' => 'Example'], "Body\n---\nMore");

        $result = $file->put_front_matter($input)->get_front_matter();

        $this->assertSame(['title' => 'Example'], $result->header);
        $this->assertSame("Body\n---\nMore", $result->content);
        $this->assertSame($file->getUri(), $result->filename);
    }

    public function testWritesEmptyFrontMatter(): void
    {
        $file = new PhoreTempFile();

        $file->put_front_matter(new FrontMatterFile(content: 'Body'));

        $this->assertSame("---\n---\nBody", $file->get_contents());
    }

    public function testMissingOpeningDelimiterReportsFilenameAndLine(): void
    {
        $file = new PhoreTempFile();
        $file->set_contents("title: Example\n---\nBody");

        try {
            $file->get_front_matter();
            $this->fail('Expected FileParsingException.');
        } catch (FileParsingException $e) {
            $this->assertStringContainsString($file->getUri(), $e->getMessage());
            $this->assertStringContainsString('line 1', $e->getMessage());
        }
    }

    public function testMissingClosingDelimiterReportsFilenameAndLine(): void
    {
        $file = new PhoreTempFile();
        $file->set_contents("---\ntitle: Example\nBody");

        try {
            $file->get_front_matter();
            $this->fail('Expected FileParsingException.');
        } catch (FileParsingException $e) {
            $this->assertStringContainsString($file->getUri(), $e->getMessage());
            $this->assertStringContainsString('line 3', $e->getMessage());
        }
    }

    public function testYamlParserErrorReportsFilenameAndSourceLine(): void
    {
        $file = new PhoreTempFile();
        $file->set_contents("---\ntitle: valid\n\tbroken: value\n---\nBody");

        try {
            $file->get_front_matter();
            $this->fail('Expected FileParsingException.');
        } catch (FileParsingException $e) {
            $this->assertStringContainsString($file->getUri(), $e->getMessage());
            $this->assertStringContainsString('line 3', $e->getMessage());
            $this->assertStringContainsString('column 1', $e->getMessage());
            $this->assertInstanceOf(YamlDecodeException::class, $e->getPrevious());
            $this->assertSame(2, $e->getPrevious()->getErrorLine());
            $this->assertSame(1, $e->getPrevious()->getErrorColumn());
            $this->assertSame("\tbroken: value", $e->getPrevious()->getErrorSourceLine());
        }
    }

    public function testCastsHeaderWhenHydratorIsAvailable(): void
    {
        if ( ! function_exists('phore_hydrate')) {
            $this->markTestSkipped('phore/hydrator is not installed.');
        }

        $file = new PhoreTempFile();
        $file->set_contents("---\ntitle: Example\n---\nBody");

        /** @var FrontMatterFile<FrontMatterHeader> $frontMatter */
        $frontMatter = $file->get_front_matter(FrontMatterHeader::class);

        $this->assertInstanceOf(FrontMatterHeader::class, $frontMatter->header);
        $this->assertSame('Example', $frontMatter->header->title);
    }
}

class FrontMatterHeader
{
    public string $title;
}

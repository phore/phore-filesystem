# phore-filesystem

[![Actions Status](https://github.com/phore/phore-filesystem/workflows/tests/badge.svg)](https://github.com/phore/phore-filesystem/actions)


File access functions


- Working with sub-paths 
- Checking symbolic links


## Installation

```
compser require phore/filesystem
```


## General usage

```php
echo phore_uri("/tmp/some.file")->withDirName();
```

will result in

```
/tmp
```

## Subpath

```php
echo phore_uri("/tmp")->withSubPath("./some/other/file")
```

```
/tmp/some/other/file
```

## Assertions

```php
phore_uri("/tmp")->assertIsFile()->assertIsWritable();
```

## Reading YAML

```php
phore_uri("/tmp/somefile.yml")->assertFile()->get_yaml();
```

## Front matter files

Jekyll-style YAML front matter can be read together with the remaining content:

```php
$document = phore_file("post.md")->get_front_matter();
echo $document->header["title"];
echo $document->content;
```

Pass a class name to hydrate the header when `phore/hydrator` is installed:

```php
$document = phore_file("post.md")->get_front_matter(PostHeader::class);
echo $document->header->title;
```

Create or replace a front matter file with `put_front_matter()`:

```php
phore_file("post.md")->put_front_matter(
    new \Phore\FileSystem\FrontMatterFile(null, ["title" => "Example"], "Content")
);
```


## Tempoary Files

Will be unlinked when object destructs.

```
$file = new PhoreTempFile();
```

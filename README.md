# phore-filesystem

[![Actions Status](https://github.com/phore/phore-filesystem/workflows/tests/badge.svg)](https://github.com/phore/phore-filesystem/actions)


File access functions

## Version 2

Version 2 requires **PHP 8.5 or newer** and intentionally introduces breaking security defaults. New filesystem entry points do not follow symbolic links unless `followSymlinks: true` is explicitly configured. Root-bound objects carry their security context through derived files, directories, walks and streams.

The current v1 release line remains `v1.1.x`; this pull request prepares `v2.0.0`.


- Working with sub-paths 
- Checking symbolic links


## Installation

```
composer require phore/filesystem:^2.0
```


## Security-first usage

```php
$root = phore_dir('/srv/site/docs', [
    'rootDir' => '/srv/site/docs',
]);

$page = $root->withSubPath('index.md')->asFile();
echo $page->get_contents();
```

The default is `followSymlinks=false`. Enable link following only deliberately and keep a `rootDir` when links must remain inside a bounded tree.

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

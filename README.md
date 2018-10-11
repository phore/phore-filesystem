# phore-filesystem
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


## Tempoary Files

Will be unlinked when object destructs.

```
$file = new PhoreTempFile();
```
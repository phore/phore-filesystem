# phore-filesystem
File access functions


## Installation

```
compser require phore/filesystem
```


## General usage

```php
echo phore_path("/tmp/some.file")->withDirName();
```

will result in

```
/tmp
```

## Subpath

```php
echo phore_path("/tmp")->withSubPath("./some/other/file")
```

```
/tmp/some/other/file
```

## Assertions

```php
phore_path("/tmp")->assertIsFile()->assertIsWritable();
```

## Reading YAML

```
phore_path("/tmp/somefile.yml")->asFile()->get_yaml();
```
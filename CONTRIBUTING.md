# Contributing

## Code quality tooling

This repo ships configuration for [`craftcms/ecs`](https://github.com/craftcms/ecs),
[`craftcms/phpstan`](https://github.com/craftcms/phpstan) and
[`craftcms/rector`](https://github.com/craftcms/rector):

```sh
composer install
vendor/bin/ecs check
vendor/bin/phpstan analyse
vendor/bin/rector process --dry-run
```

PHPStan runs at level 8. All three must pass clean before a release.

## Local development

Install the plugin into a Craft 5 site through a Composer path repository:

```json
{
    "repositories": [
        { "type": "path", "url": "../craft-cleverreach" }
    ]
}
```

```sh
composer require kernpfad/craft-cleverreach:@dev
php craft plugin/install cleverreach
```

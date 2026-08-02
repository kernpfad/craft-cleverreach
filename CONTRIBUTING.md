# Contributing

## Code quality tooling

This repo ships configuration for [`craftcms/ecs`](https://github.com/craftcms/ecs),
[`phpstan/phpstan`](https://github.com/phpstan/phpstan) (^2.2) and
[`craftcms/rector`](https://github.com/craftcms/rector) (`dev-craft6`, Rector 2).

```sh
composer install
composer check
```

`composer check` runs ECS, PHPStan (level 8), Rector dry-run, and unit tests.
Individual scripts:

| Script | Purpose |
|---|---|
| `composer check-cs` / `composer fix-cs` | Easy Coding Standard |
| `composer phpstan` | Static analysis |
| `composer rector` / `composer rector:fix` | Rector dry-run / apply |
| `composer test:unit` | Unit tests (no Craft boot) |
| `composer test:integration` | Integration tests (needs Craft + DB) |

All of ECS, PHPStan and Rector must pass clean before a release. Pull requests
should keep `composer check` green.

## Tests

```sh
composer test:unit
composer test:integration
```

Unit tests run without booting Craft. Integration tests boot a real Craft
application and exercise the plugin against real records, so they need a
configured test database.

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
php craft plugin/install commerce-klaviyo
```

## Pull requests

Use the PR template. Update `CHANGELOG.md` when behaviour changes.

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md).

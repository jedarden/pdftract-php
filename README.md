# pdftract PHP SDK

PHP SDK for the pdftract PDF processing service.

## Installation

> **Note:** this package is not published on Packagist yet, so plain
> `composer require jedarden/pdftract` will not resolve. Install it from the
> source repository as shown below.

Add the repository to your project's `composer.json`, then require the package:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://git.ardenone.com/jedarden/pdftract-php.git"
        }
    ],
    "require": {
        "jedarden/pdftract": "dev-main"
    }
}
```

```bash
composer update jedarden/pdftract
```

There are no tagged releases yet, so `dev-main` is the only available
constraint. Depending on it means your project needs
`"minimum-stability": "dev"` (with `"prefer-stable": true`) or an explicit
`dev-main` alias.

The repository requires authentication. Store a Gitea access token once:

```bash
composer config --global --auth http-basic.git.ardenone.com <username> <token>
```

Alternatively, clone the repository and point Composer at the local checkout:

```json
{
    "repositories": [
        { "type": "path", "url": "../pdftract-php" }
    ],
    "require": {
        "jedarden/pdftract": "*"
    }
}
```

## Requirements

- PHP 8.2 or higher
- psr/log ^3.0

## Usage

```php
use Jedarden\Pdftract\Client;

$client = new Client('http://localhost:8080');

// TODO: Add usage examples
```

## Development

```bash
# Install dependencies
composer install

# Run tests
./vendor/bin/phpunit
```

## License

MIT License - see LICENSE file for details.

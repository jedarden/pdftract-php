# pdftract PHP SDK

PHP SDK for the pdftract PDF processing service.

## Installation

> **Note:** this package is not published on Packagist yet, so plain
> `composer require jedarden/pdftract` will not resolve. Install it from the
> source repository as shown below.

Add the repository to your project's `composer.json`, then require the
package. Pin a tagged release — `"^0.1.0"` resolves from the `0.1.0` tag and
installs cleanly in projects that require stable packages, with no
`minimum-stability` override needed:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://git.ardenone.com/jedarden/pdftract-php.git"
        }
    ],
    "require": {
        "jedarden/pdftract": "^0.1.0"
    }
}
```

```bash
composer update jedarden/pdftract
```

To follow the latest work on `main` instead of a tagged release, require
`"jedarden/pdftract": "dev-main"`. Depending on the tracking branch means
your project needs `"minimum-stability": "dev"` (with
`"prefer-stable": true`) or an explicit `dev-main` alias — prefer a tagged
release when you just want a working install.

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

# Run the real-server suite against a serve-capable pdftract binary
PDFTRACT_SERVE_BIN=/path/to/pdftract ./vendor/bin/phpunit --group real-server
```

The default suite enforces the PSR-3 logging contract (log levels, entry
shape, request/response/error logging on `Client`) — there is no separate
script to remember. The dedicated cases live in
`tests/ClientPsr3LoggerTest.php`, with the buffered route's request/error
entries pinned in `tests/ClientBufferedRouteTest.php` and the streaming
route's in `tests/ClientStreamingRouteTest.php` — the streamed entries pin
the execution debug record with its idle bound, the abandon record a
dropped or failing generator emits, and the idle-bound, in-band-error,
rejected-status, and transport-failure records; `--group psr3-logging`
runs just the logging cases. The route-agnostic pins — the null-logger
default and the contract holding for *any* PSR-3 implementation — stay in
`tests/ClientPsr3LoggerTest.php` rather than being re-held per route. The
standalone verifier that preceded this
coverage, `tests/Retired/verify_psr3_logger.php`, drove the retired CLI
subprocess transport and is kept only as a record — the suite never executes
it.

Three suites are gated on a real pdftract binary and skip cleanly (with a
message, never a failure) when none is configured, so a plain run stays
green with no build:

- `tests/ClientBinaryConformanceTest.php` (group `binary-conformance`) —
  runs when `PDFTRACT_BIN` points at a pdftract binary, and exercises the
  retired CLI-subprocess conformance cases against it.
- `tests/ClientServeParityTest.php` (group `serve-parity`) — runs when
  `PDFTRACT_SERVE_BIN` points at one binary, and pins each covered serve
  route's output against its CLI equivalent with the SDK as the third
  party in every comparison.
- `tests/ClientRealServerTest.php` (group `real-server`) — runs when
  `PDFTRACT_SERVE_BIN` points at a serve-capable binary, and drives the
  canonical HTTP client through a live `pdftract --serve` process: the
  buffered and streaming POST routes, the GET routes (the root route
  banner, the `/extract` file-path guard's 404, and the `/health`
  banner), the real multipart parser's rejections, real NDJSON chunk
  framing, and the idle bound on both sides — every route of the audited
  serve inventory (`docs/notes/serve-parity-gap.md`).
  A binary whose serve surface is broken (the stock conformance binaries'
  documented ConnectInfo defect — see
  `docs/notes/serve-parity-gap.md`, Addendum 2026-09-27c) skips the suite
  with the diagnosis rather than failing it. To produce a serve-capable
  binary — built from current upstream and refused unless its `GET
  /health` smoke gate passes — run `scripts/build-serve-bin.sh` and point
  `PDFTRACT_SERVE_BIN` at the path it prints (see
  `docs/notes/serve-parity-gap.md`, Addendum 2026-09-27e).

## License

MIT License - see LICENSE file for details.

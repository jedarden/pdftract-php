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
- ext-curl — every request goes out over curl. `composer.json` does not
  declare the extension yet, so Composer will not warn you when it is
  missing; the first request instead fails with
  `Call to undefined function curl_init()`.

## Usage

The client talks to a `pdftract --serve` HTTP endpoint. Start the server:

```bash
pdftract serve --bind 127.0.0.1:8080
```

Then point a client at it:

```php
use Jedarden\Pdftract\Client;

$client = new Client('http://127.0.0.1:8080');
```

Every method POSTs the document as a `multipart/form-data` upload — the
server never reads files from its own filesystem on a client's behalf — so
each call takes the document as a `Source` (a file path or in-memory bytes)
or as a raw PDF byte string. Every request is bounded by a timeout:
buffered calls get a total wall-clock bound, streaming calls an *idle*
bound that resets each time the server produces output
(see [Timeouts](#timeouts)).

The constructor takes the base URL plus three optional arguments — an API
key, a PSR-3 logger, and the default timeout:

```php
$client = new Client(
    baseUrl: 'http://pdftract.internal:8080',
    apiKey: getenv('PDFTRACT_API_KEY') ?: null,  // Authorization: Bearer …
    timeoutSeconds: 600.0,
);
```

The serve API has no built-in authentication; the API key exists for
deployments that put an authenticating reverse proxy in front of it. An
empty base URL, a negative or non-finite timeout, or a non-numeric
`'timeout'` option throws `ConfigurationException` before anything is sent.

### extract(): structured data

`extract(Source|string $pdf, array $options = []): array` POSTs to
`/extract` and returns the decoded JSON response verbatim — the client
does not schema-validate or remap it. The response's documented shape is
`schema_version`, `metadata`, `pages`, plus `extraction_quality` and
`errors`:

```php
use Jedarden\Pdftract\Source;

$doc = $client->extract(Source::file('/tmp/invoice.pdf'));

echo $doc['metadata']['page_count'], " pages\n";

foreach ($doc['pages'] as $page) {
    foreach ($page['spans'] as $span) {
        echo $span['text'], "\n";
    }
}
```

`metadata` carries `title`, `author`, `page_count`, `pdf_version`,
`is_encrypted`, `contains_javascript`; each entry of `pages` carries
`page_index`, `page_number`, `width`, `height`, and the extracted `spans`,
`blocks`, and `tables`.

When the PDF is already in memory, pass the bytes directly — a
`Source::bytes()` or a plain `string`:

```php
$doc = $client->extract(Source::bytes($pdfBytes));
$doc = $client->extract($pdfBytes); // equivalent
```

Options go in the second argument, keyed by the camelCase option name and
forwarded to the server as multipart form fields (`null` and `false`
values are omitted, `true` becomes the string `'true'`). The `'timeout'`
option is popped client-side and never forwarded; an option key that
normalises to the upload field names `file` or `pdf` is rejected outright,
because those names carry the document itself:

```php
$doc = $client->extract(
    Source::file('/tmp/scan.pdf'),
    ['ocrLanguage' => 'eng', 'pages' => '1-5', 'noCache' => true, 'timeout' => 600],
);
```

Fields the serve API reads on every POST route: `pages`, `ocrLanguage`,
`ocrDpi`, `receipts`, `noCache`, `fullRender`, `maxDecompressGb`, and
`markdownAnchors`. Forwarding is verbatim and does not imply effect —
`markdownAnchors` is accepted by every route yet changes no serve response,
so there is no way to obtain markdown through this client
(see [Methods the HTTP client does not offer](#methods-the-http-client-does-not-offer)).

### extractText(): plain text

`extractText(Source|string $pdf, array $options = []): string` POSTs to
`/extract/text` and returns the response body verbatim — one extracted
text span per line:

```php
$text = $client->extractText(Source::file('/tmp/invoice.pdf'));
echo $text;
```

The same `$options` array applies as for `extract()`.

### extractStream(): streamed NDJSON

`extractStream(Source|string $pdf, array $options = []): \Generator` POSTs
to `/extract/stream` and yields one decoded JSON object per NDJSON line as
the server produces it, instead of holding everything back until the whole
document is done:

```php
foreach ($client->extractStream(Source::file('/tmp/invoice.pdf')) as $record) {
    // one decoded JSON object per line, as it arrives
}
```

Blank and undecodable lines are skipped. If the server reports a
mid-extraction failure it sends a final `{"error": ...}` line, which the
client raises as a `PdftractException` from inside the loop rather than
yielding it as a record. Abandoning the generator early (`break`, an
uncaught exception, the generator being garbage-collected) closes the
connection immediately.

### Timeouts

The fourth constructor argument sets the default bound, 300 seconds. The
per-call `'timeout'` option overrides it; `0` disables the bound:

```php
$client = new Client('http://127.0.0.1:8080', timeoutSeconds: 600.0);

$doc = $client->extract(Source::file('/tmp/big.pdf'), ['timeout' => 900]);
$doc = $client->extract(Source::file('/tmp/big.pdf'), ['timeout' => 0]); // unbounded
```

The two call families bind differently:

- **Buffered** (`extract`, `extractText`): the bound is total wall-clock
  time for the whole request.
- **Streaming** (`extractStream`): the bound is *idle* time — it resets
  every time the server produces output, so a long but healthy stream is
  not cut off while a silent stall still is.

Exceeding a bound throws `TimeoutException`, which reports the exceeded
value from `getTimeoutSeconds()`.

### Logging (PSR-3)

Pass any `psr/log` v3 `LoggerInterface` as the third constructor argument;
the default (`null`) logs nothing. The client type-hints the bare
interface — no abstract base class, no concrete logger type — so any
compliant implementation works. Monolog 3, for example (installed
separately with `composer require monolog/monolog`):

```php
use Jedarden\Pdftract\Client;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

$logger = new Logger('pdftract');
$logger->pushHandler(new StreamHandler('php://stderr', Level::Debug));

$client = new Client('http://127.0.0.1:8080', logger: $logger);
```

Every request emits a `debug` execution record just before it goes out —
`{method, url, timeout}` for a buffered call, `{method, url, idle_timeout}`
for a stream. Failures emit `error` records carrying the URL and the HTTP
status or curl error; a stalled or failed stream, an in-band server error,
an undecodable response, and an abandoned stream generator each get their
own record. Everything goes to the logger — the client never echoes to
STDOUT itself.

### Error handling

Every exception the client raises extends `PdftractException`, which
carries the server's fields: `getStatusCode()` (the HTTP status, null for
transport-level failures), `getErrorCode()` (the machine-readable code
from the serve API's `{error, message, hint}` body), and `getHint()` (the
server's actionable hint — an encrypted document arrives with the hint to
pass a password).

| Exception | Raised when |
|---|---|
| `ConfigurationException` | the request is unusable before it is sent: empty base URL, negative or non-finite timeout, a `'timeout'` option that is not a number, or an option key normalising to the reserved upload fields `file`/`pdf` |
| `ConnectionException` | the server cannot be reached — connection refused, DNS failure, reset — or the request cannot be initialised |
| `TimeoutException` | a buffered request exceeds its wall-clock bound, or a stream stalls longer than its idle bound; `getTimeoutSeconds()` returns the bound |
| `ValidationException` | HTTP 400/413/422 — a rejected request shape (`MISSING_FIELD`, `REQUEST_TOO_LARGE`) or a document the server cannot process (`ENCRYPTED`, `WRONG_PASSWORD`, `CORRUPT_PDF`, `EXTRACTION_ERROR`, `DECOMPRESSION_LIMIT`). Retrying the same request repeats the failure; the request or the document has to change. |
| `AuthenticationException` | HTTP 401/403 — the serve API has no built-in authentication, so in practice these come from an authenticating reverse proxy in front of it |
| `NotFoundException` | HTTP 404 |
| `RateLimitException` | HTTP 429 |
| `PdftractException` (base) | any other non-2xx status (the 5xx family, a proxy interposing a stray 4xx page), a 2xx response whose body is not valid JSON, an unreadable file-backed `Source`, or a stream's in-band `{"error": ...}` record |

The status alone picks the class — a foreign body under a mapped status
(a proxy's HTML 404 page) still raises that status's subclass, carrying
the excerpted body as its message. `getCode()` equals the HTTP status on
server errors and `0` on transport-level failures.

```php
use Jedarden\Pdftract\Client;
use Jedarden\Pdftract\ConnectionException;
use Jedarden\Pdftract\PdftractException;
use Jedarden\Pdftract\Source;
use Jedarden\Pdftract\TimeoutException;
use Jedarden\Pdftract\ValidationException;

$client = new Client('http://127.0.0.1:8080');

try {
    $doc = $client->extract(Source::file('/tmp/invoice.pdf'));
} catch (ValidationException $e) {
    // 400/413/422 — the request or the document must change.
    error_log(sprintf(
        'pdftract rejected the request: %s (code %s, hint: %s)',
        $e->getMessage(),
        $e->getErrorCode() ?? 'none',
        $e->getHint() ?? 'none',
    ));
} catch (TimeoutException $e) {
    // Retry later, or raise the bound with the 'timeout' option.
} catch (ConnectionException $e) {
    // The server is unreachable — check the endpoint.
} catch (PdftractException $e) {
    // Everything else: 5xx, an undecodable response, an unreadable file.
}
```

### Methods the HTTP client does not offer

The pdftract CLI documents nine methods; the serve API exposes routes for
three. There is no HTTP route at all for `search()`, `getMetadata()`,
`hash()`, `classify()`, `verifyReceipt()`, or `extractMarkdown()`, so this
client deliberately does not offer them — calling one is a PHP
`Error: Call to undefined method`, and the SDK does not fall back to
shelling out to a local binary. Whether and how the six join the client is
a decision still open in this repository's plan
(`docs/plan/plan.md`, gated on the `bf-4bd` finding); the complete route
inventory and the evidence for each gap is
`docs/notes/serve-parity-gap.md`. Two of the gaps matter if you reach for
a workaround: no serve response has a markdown representation at all (the
`markdownAnchors` field is accepted by every POST route but is inert over
HTTP), and the fingerprint embedded in a `/extract` response is computed
over a different input than the CLI's `pdftract hash` prints, so it is
not a drop-in `hash()`.

## Development

```bash
# Install dependencies
composer install

# Run tests
./vendor/bin/phpunit

# Definition of done: the same suite, via the gate script (resolves
# php/composer on machines where neither is on PATH)
scripts/definition-of-done.sh --fast

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

Two suites are gated on a serve-capable pdftract binary and skip cleanly
(with a message, never a failure) when none is configured, so a plain run
stays green with no build:

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

Two further suites were retired with the CLI-subprocess transport they
drove and live on only as inert records under `tests/Retired/` (group
`retired-cli-subprocess`, excluded from the default suite, every case
self-skipping in `setUp()` — they never run against the canonical HTTP
client):

- `tests/Retired/ClientBinaryConformanceTest.php` — swept the vendored
  conformance cases against a real `pdftract` binary through the retired
  subprocess Client (loaded by file path, now pending deletion). Its
  serve-routed cases (extract, extract_text, extract_stream) are superseded
  on the canonical client by the two suites above.
- `tests/Retired/ClientHashConformanceTest.php` — pinned the retired
  wrapper's `hash()` against the real binary's `pdftract hash` (INV-13,
  determinism, the structural collision twins, byte equality). Unportable
  to the HTTP client until a `/hash` serve route exists — the serve API has
  none (`docs/notes/serve-parity-gap.md`, gated on bf-4bd) — so the pins are
  kept for whoever resurrects a `hash()` surface after that.

### Conformance binaries and the PDFTRACT_BIN gate

The binary conformance suites — today the retired records above, and any
harness that re-lands them — run `Client` methods against real `pdftract`
builds in two variants, both built from the upstream checkout by one
script:

```bash
scripts/build-conformance-binaries.sh   # PDFTRACT_REPO defaults to ../pdftract
```

- `pdftract-default` — the upstream crate's default cargo feature set
  (empty: a plain release build).
- `pdftract-full` — feature-complete (`--features profiles,grep`). Only
  the feature-gated CLI surface needs it (`search()` → the `grep`
  subcommand); when upstream's `profiles` feature does not compile at a
  given checkout the script falls back to `grep` alone, which covers every
  feature-gated surface the SDK's methods exercise.

Both variants compile in one cargo target dir and are copied to
`<upstream>/target/conformance/` — nothing is written into this repo's
tree. The script fails fast with the seed recipe when a git-archive
extraction is missing the untracked build-data directories upstream's
generic `build/` gitignore pattern swallows
(`crates/pdftract-core/build/CHECKSUMS.sha256`), warns loudly when the
upstream tree is dirty (`PDFTRACT_REV` covers `.git`-less extractions),
and smoke-gates both binaries on `--help` before printing the export lines
the harness reads:

```bash
export PDFTRACT_BIN=…/target/conformance/pdftract-default
export PDFTRACT_BIN_FULL=…/target/conformance/pdftract-full
```

Gating and clean-skip semantics:

- `PDFTRACT_BIN` unset — every binary conformance case skips with a
  message pointing at the build script: a clean skip, never a failure.
  Set but not an executable file — the case fails loudly instead, so a
  misconfigured path cannot masquerade as a skip.
- `PDFTRACT_BIN_FULL` unset — only the feature-gated cases (search) skip;
  every ungated-surface case still runs against `PDFTRACT_BIN`.
- A plain `vendor/bin/phpunit` run needs no binary at all: the retired
  suites are excluded via the `retired-cli-subprocess` group in
  `phpunit.xml` and self-skip in `setUp()` regardless, and the two live
  binary-gated suites key on `PDFTRACT_SERVE_BIN` (above), skipping with a
  message the same way when unset. Because a clean skip is
  indistinguishable from a suite that silently never ran, CI runs
  `scripts/ci-assert-gated-ran.php` on the gated invocation's junit log so
  a run that lost its binary fails the build instead of passing green.

The serve-capable build is a different binary from a different script —
`scripts/build-serve-bin.sh`, feeding `PDFTRACT_SERVE_BIN` (above); the
stock conformance binaries cannot serve, so do not cross-wire the two
variables. Upstream caveats and the re-landing decision for the retired
suites: `docs/notes/binary-conformance-harness.md` and the "Conformance
suite" section of `docs/notes/adr1-serve-parity-memo.md`.

### Definition of done

```bash
scripts/definition-of-done.sh --fast
```

The repo's gate: the PHPUnit suite, green. The script resolves `php`
(falling back to the nix-store `php-with-extensions` profile where plain
`php` lacks the curl extension), resolves `composer` (nix-store profile,
then a checksum-pinned composer 2.11.3 phar bootstrapped into a temp dir
when the profile has been garbage-collected and `vendor/` is absent),
runs `composer install` only when `vendor/` is missing, and execs
`php vendor/bin/phpunit` with any extra arguments forwarded. `--fast` is
consumed rather than forwarded — PHPUnit rejects unknown options — and
selects nothing today because the suite has no slow partition. The gated
suites skip cleanly without their binaries (see above), so a plain green
run needs no binary at all.

## License

MIT License - see LICENSE file for details.

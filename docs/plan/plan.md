# pdftract-php — Plan

This file is the single planning document for `pdftract-php`, the PHP SDK for
the `pdftract` PDF processing tool. It was created retroactively on
2026-07-20 during a fleet-wide artifact-improvement audit — no earlier plan
existed, so this starts honestly from the current shipped state rather than
fabricating history.

## What this repo ships

A Composer library (`jedarden/pdftract`, PSR-4 namespace `Jedarden\Pdftract\`)
providing a PHP `Client` class with methods (`extract`, `extractText`,
`extractMarkdown`, `extractStream`, `search`, `getMetadata`, `hash`,
`classify`, `verifyReceipt`) plus a generated model/exception hierarchy
(`src/Pdftract/Models/*`, `src/Pdftract/Codegen/*Exception.php`). As shipped
today (`origin/main`), the `Client` works by shelling out to a local
`pdftract` binary via `proc_open()` and parsing its stdout as JSON/NDJSON —
it is a CLI-subprocess wrapper, not an HTTP client, despite `pdftract` itself
supporting a `--serve` HTTP mode.

There is no live deployed surface for this repo specifically (it is a
library, not a service) and it is not currently listed on Packagist, so
`composer require jedarden/pdftract` only works via a VCS repository entry,
not the default Packagist registry.

## Known issues found during the 2026-07-20 audit

- The repo's working tree (on the lab checkout) has ~7 weeks of uncommitted,
  abandoned work-in-progress (`src/Client.php`, `src/Codegen/`,
  `src/Models/`, plus modified `composer.json`/`README.md`/`phpunit.xml`/
  `tests/ConformanceTest.php`) that half-migrates the SDK from the
  subprocess-wrapper design to an HTTP-client design, changes the PSR-4
  autoload root from `src/Pdftract/` to `src/`, but leaves the old
  `src/Pdftract/` tree in place and only reimplements 2 of 25 model classes
  and a stub `Codegen\Methods` ("This class will be populated..."). It is
  not safe to finish or discard unilaterally without a decision on the
  target architecture — see ADR-1 below.
- The committed `tests/ConformanceTest.php` (on `origin/main`) loads fixtures
  from `__DIR__ . '/../../../../tests/sdk-conformance/'`, a path that only
  resolves if this repo is checked out as a subdirectory of a `pdftract`
  monorepo. In the actual standalone `pdftract-php` repo that directory does
  not exist, so `setUp()` calls `$this->fail(...)` immediately — every test
  in the shipped suite fails before it can run.
- No CI is configured for this repo (no workflow files, and no
  `pdftract-php-build` entry among the fleet's Argo `WorkflowTemplate`s), so
  the broken conformance suite above has never been caught by automation.
- `Client::extractText()`, `extractMarkdown()`, `extractStream()`,
  `search()`, and `verifyReceipt()` each hand-roll their own ~30-line
  `proc_open`/pipe-handling block instead of reusing the private `exec()`
  helper that `extract()`, `getMetadata()`, `hash()`, and `classify()`
  already share — six near-identical copies of the same subprocess logic.
- No timeout is set on any `proc_open()` call — a hung or slow `pdftract`
  invocation (e.g. a pathological PDF) blocks the calling PHP process
  indefinitely.
- `origin` pointed directly at `github.com/jedarden/pdftract-php` with no
  Forgejo repo behind it, in violation of this workspace's
  Forgejo-primary/GitHub-mirror hosting policy. Fixed as part of this audit:
  created `git.ardenone.com/jedarden/pdftract-php`, configured a push mirror
  to GitHub, and repointed local `origin` at Forgejo.

## ADR-1: 2026-07-20 — Commit to an HTTP-client transport, retire the CLI-subprocess wrapper

### Context

`pdftract-php`'s `Client` currently talks to `pdftract` exclusively by
`proc_open()`-ing a local binary and parsing its stdout. This requires the
`pdftract` binary to be installed and executable on every host that uses the
SDK, requires `proc_open`/`shell_exec` to be enabled (routinely disabled on
shared/managed PHP hosting and in some hardened PHP-FPM pools), and offers no
timeout, connection pooling, retries, or concurrent-request support — each
call blocks a PHP worker for the full lifetime of a subprocess.

`pdftract` (the underlying tool, per this workspace's tracked project notes)
already supports a `--serve` mode exposing an HTTP API. Someone already
started migrating this SDK toward that model: the repo's working tree
contains ~7 weeks of uncommitted, unfinished work that introduces an
HTTP-oriented `Client(string $baseUrl, ?string $apiKey)` constructor,
restructures the PSR-4 autoload root, and begins (but does not finish)
replacing the generated model/exception tree. That WIP is currently
unshippable — it deletes real usage docs and working methods in favor of
stub classes — but it correctly identifies the direction this SDK needs to
go.

Left as-is, the repo is stuck straddling two designs: the committed code is
a complete-but-architecturally-limited subprocess wrapper, and the
uncommitted code is a promising-but-incomplete HTTP client. Neither is in a
good state to build on without an explicit decision.

### Decision

`pdftract-php` will become an HTTP client against `pdftract --serve`,
replacing the `proc_open` subprocess wrapper as the SDK's sole transport.

Concretely:
- `Client` takes a base URL (and optional API key/PSR-3 logger), matching
  the direction already started in the uncommitted WIP, not a binary path.
- HTTP calls go through a small internal transport using PHP's `curl`
  extension (already a near-universal PHP dependency) or a PSR-18 client if
  one is later needed for framework interop — no new hard dependency is
  added for v1.
- Requests get an explicit, configurable timeout (default a few seconds for
  metadata/hash calls, longer for extract/OCR calls) and a single
  request-building/error-handling path shared by every public method — the
  six-way `proc_open` duplication goes away by construction.
- Streaming endpoints (`extractStream`, `search`) use chunked HTTP reads
  instead of reading subprocess stdout line-by-line.
- The generated `Models`/`Codegen` exception tree is kept (it's a reasonable
  representation of the API's data shapes) but regenerated/audited against
  the actual HTTP OpenAPI schema `pdftract --serve` exposes, rather than
  the CLI's JSON output shape, since the two are not guaranteed identical.
- The conformance-suite path bug is fixed as part of this work (fixtures
  vendored into this repo, or fetched from the `pdftract` repo's release
  artifacts — not read via `../../../../` relative to a monorepo layout that
  doesn't exist here) so tests can actually run standalone and in CI.

### Alternatives Considered

1. **Keep the CLI-subprocess design, just fix its bugs** (dedupe the
   `proc_open` boilerplate into `exec()`, add timeouts). Rejected: it caps
   the SDK's usefulness at "hosts with the pdftract binary installed and
   shell access enabled," which excludes most managed/shared PHP hosting and
   doesn't scale past one process per request. It doesn't resolve the
   half-migrated working-tree state either.
2. **Support both transports behind a shared interface** (strategy pattern,
   `Client` picks CLI or HTTP based on constructor args). Rejected for v1:
   real added complexity (two code paths to test and keep at parity) for a
   library with effectively one current consumer; can be revisited later if
   a concrete need for the CLI path re-emerges (e.g. an offline/air-gapped
   use case).
3. **Finish the existing WIP as a mechanical file move** without addressing
   the conformance-suite bug or adding CI. Rejected: would ship a
   "working" SDK that still has no verifiable test suite, repeating the
   exact failure mode (broken tests nobody runs) that let the current state
   go unnoticed for 7 weeks.

### Consequences

- Deploying `pdftract` with `--serve` enabled becomes a prerequisite for
  this SDK; that mode needs to be documented (or defaulted) somewhere
  discoverable — tracked as a follow-up bead.
- The stale WIP (`src/Client.php`, `src/Codegen/`, `src/Models/` at the
  `src/` root, and the modified `composer.json`/`README.md`/`phpunit.xml`/
  `tests/ConformanceTest.php`) should be treated as the starting point for
  this migration, not thrown away — but it needs the remaining ~23 model
  classes, the full exception hierarchy, a real `Methods` implementation
  (currently a stub), and the old `src/Pdftract/` tree removed once nothing
  references it.
- Existing callers relying on binary-path construction
  (`new Client('pdftract')`) will need to migrate to
  `new Client('http://host:port')` — this is a breaking change, appropriate
  for a pre-1.0/pre-Packagist-listing library with no known external
  consumers yet.
- CI (an Argo `WorkflowTemplate`, e.g. `pdftract-php-ci`) becomes worth
  adding now that there's a real HTTP surface to smoke-test against a
  `pdftract --serve` container in the build pipeline, rather than against
  an unreachable local binary.

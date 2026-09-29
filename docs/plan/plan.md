# pdftract-php — Plan

This file is the single planning document for `pdftract-php`, the PHP SDK for
the `pdftract` PDF processing tool. It was created retroactively on
2026-07-20 during a fleet-wide artifact-improvement audit — no earlier plan
existed, so this starts honestly from the current shipped state rather than
fabricating history. The "What this repo ships" and "Known issues" sections
below were reconciled against the landed ADR-1 migration on 2026-09-28 (bead
`pdfphp-960c1fbe`); the ADR-1 transport-scope amendment itself is a separate,
still-open edit (bead `pdfphp-b3788e75`), so the ADR-1 section reads as
written in 2026-07.

## What this repo ships

A Composer library (`jedarden/pdftract`, PSR-4 namespace `Jedarden\Pdftract\`
rooted at `src/`) whose canonical surface is the HTTP `Client`
(`src/Client.php`, bead `pdfphp-e0998515`) for a `pdftract --serve` endpoint
— the transport ADR-1 below committed the SDK to. The client exposes
`extract()` (POST /extract, JSON), `extractText()` (POST /extract/text), and
`extractStream()` (POST /extract/stream, streamed NDJSON), each POSTing the
document as a multipart/form-data upload over curl, mapping non-2xx
responses to the exception tree at the `src/` root (`PdftractException` plus
ten status-mapped subclasses; commit `d0b9b59`, bead `pdfphp-92801363`), and
carrying an API-key Bearer header, a PSR-3 logger, and a configurable
timeout bound (total wall-clock for buffered calls, idle-bound for
streaming; default `DEFAULT_TIMEOUT_SECONDS` = 300, overridable per call
with a `'timeout'` option).

It deliberately offers *not* the CLI transport's other six documented
methods (`search`, `getMetadata`, `hash`, `classify`, `verifyReceipt`,
`extractMarkdown`): the serve API exposes no HTTP route for them (the full
route inventory is `docs/notes/serve-parity-gap.md`), so adding them is the
human decision gated on `bf-4bd` (open, OPS-GATED), with the engineering
follow-through on `pdfphp-decde78f` (in progress). The same evidence base is
what resolved the `Codegen` stubs by deletion
(`docs/notes/codegen-stubs-resolution.md`, bead `pdfphp-decde78f`).

The retired CLI-subprocess wrapper is retired, not yet fully gone. Its
`src/Pdftract/` tree — the `proc_open()` `Client`, the 25 generated model
classes, and the `src/Pdftract/Codegen/*Exception.php` files — is still in
the repo pending deletion (bead `pdfphp-ce2bbc56`, open; the remaining model
classes' port to `src/Models/` is `pdfphp-6b9a3bdb`). Nothing autoloads it:
the PSR-4 root is `src/`, so those files are unreachable shadows of live
class names. Nothing live *executes* it either any more: the two
binary-gated conformance suites that loaded it by file path as the
comparison leg (`tests/ClientBinaryConformanceTest.php`,
`tests/ClientHashConformanceTest.php`) were retired into `tests/Retired/`
with the transport they drove (bead `pdfphp-62319135`) — the conformance
sweep's serve-routed cases are superseded on the canonical client by the
`serve-parity`/`real-server` suites, and the hash pins are unportable until
`bf-4bd` adds a `/hash` serve route — so both joined the record instead of
stranding on the deletion. The retired subprocess *contract* — and now
those two suites — survive only as a record under `tests/Retired/`,
excluded from the default suite and inert by construction (partition per
`bf-4gq`, commit `f0a1d6f`).

There is no live deployed surface for this repo specifically (it is a
library, not a service) and it is not listed on Packagist: install is via a
Forgejo VCS repository entry pinning the `0.1.0` tag, as the README
documents (bead `bf-64f`). CI runs on Argo Workflows: `pdftract-php-ci` and
`pdftract-php-publish` exist as `WorkflowTemplate`s in `declarative-config`
(`k8s/iad-ci/argo-workflows/pdftract-php-ci.yaml`) and are synced to iad-ci
(visible on the cluster as of 2026-09-28); the push trigger and the first
proven-green end-to-end run are still being wired (`pdfphp-a43490a1`,
`pdfphp-715ca6c3`, `pdfphp-12c3fc2e`).

## Known issues found during the 2026-07-20 audit

(Status reconciled 2026-09-28 against the landed ADR-1 migration; resolved
items are struck through with their resolution and bead.)

- ~~The repo's working tree (on the lab checkout) has ~7 weeks of uncommitted,
  abandoned work-in-progress (`src/Client.php`, `src/Codegen/`,
  `src/Models/`, plus modified `composer.json`/`README.md`/`phpunit.xml`/
  `tests/ConformanceTest.php`) that half-migrates the SDK from the
  subprocess-wrapper design to an HTTP-client design, changes the PSR-4
  autoload root from `src/Pdftract/` to `src/`, but leaves the old
  `src/Pdftract/` tree in place and only reimplements 2 of 25 model classes
  and a stub `Codegen\Methods` ("This class will be populated..."). It is
  not safe to finish or discard unilaterally without a decision on the
  target architecture — see ADR-1 below.~~ Resolved — the decision was made
  (ADR-1) and the WIP landed as the migration rather than being finished or
  discarded piecemeal (umbrella bead `bf-4gq`, open until its tails close):
  the canonical HTTP client is `src/Client.php` (`pdfphp-e0998515`), the
  exception hierarchy is ported into the `src/` root (commit `d0b9b59`,
  `pdfphp-92801363`), the `Codegen` stubs were resolved by deletion
  (`docs/notes/codegen-stubs-resolution.md`, `pdfphp-decde78f`), and the
  legacy subprocess suite was partitioned into `tests/Retired/` rather than
  left half-alive (`pdfphp-3c3f44af`, commit `f0a1d6f`). Still open from
  this bullet: deleting the superseded `src/Pdftract/` tree
  (`pdfphp-ce2bbc56`) and porting the remaining 23 model classes to
  `src/Models/` (`pdfphp-6b9a3bdb`); the method-surface question — whether
  the six serve-unreachable CLI methods ever join the HTTP client — is
  OPS-GATED on `bf-4bd` with `pdfphp-decde78f` holding the engineering
  follow-through.
- ~~The committed `tests/ConformanceTest.php` (on `origin/main`) loads fixtures
  from `__DIR__ . '/../../../../tests/sdk-conformance/'`, a path that only
  resolves if this repo is checked out as a subdirectory of a `pdftract`
  monorepo. In the actual standalone `pdftract-php` repo that directory does
  not exist, so `setUp()` calls `$this->fail(...)` immediately — every test
  in the shipped suite fails before it can run.~~ Fixed 2026-07-30 (bead
  `bf-1o3`): the conformance suite (`cases.json`, the JSON schemas, and every
  fixture a case references) is vendored into `tests/sdk-conformance/` and the
  test reads it via `__DIR__ . '/sdk-conformance'`, so the suite runs from a
  plain clone. `tests/verify_psr3_logger.php` was carrying the same
  `../../../../` assumption and now reads a vendored fixture too — it also
  needed two fixes to run at all (its `TestLogger::log()` was `private`, which
  is a fatal PSR-3 signature violation, and `getEntriesByLevel()` returned
  `array_filter()`'s key-preserving result that callers then indexed with
  `[0]`). See `tests/sdk-conformance/README.md` for provenance and
  re-vendoring steps. (The verifier has since moved with the transport it
  drove: it is now `tests/Retired/verify_psr3_logger.php`, a record the
  suite never executes.)
- ~~No CI is configured for this repo (no workflow files, and no
  `pdftract-php-build` entry among the fleet's Argo `WorkflowTemplate`s), so
  the conformance suite above had never been exercised by automation.~~
  Resolved: `pdftract-php-ci` now exists as an Argo `WorkflowTemplate` in
  `declarative-config` (`k8s/iad-ci/argo-workflows/pdftract-php-ci.yaml`,
  drafted under `pdfphp-1b6a29fb`) and is synced to iad-ci. One pod builds
  the three conformance/serve binaries with the SDK's own scripts, then
  runs the plain suite with binaries unset (the documented baseline,
  `scripts/definition-of-done.sh`) *and* the binary-gated groups with
  `--log-junit` plus `scripts/ci-assert-gated-ran.php` — so a green build
  means the gated cases actually executed, not merely skipped.
  `pdftract-php-publish` gates tag publishes on the same suite. Still open:
  proving the template green end to end (`pdfphp-715ca6c3`) and wiring the
  push trigger (`pdfphp-a43490a1`).
- ~~`./vendor/bin/phpunit` exits non-zero on a plain `composer install` checkout
  even when all 85 tests pass: `phpunit.xml` sets `failOnWarning="true"` and
  declares a `<coverage>` report, which raises a "No code coverage driver
  available" runner warning on any machine without Xdebug or PCOV. The one-line
  fix (drop the `<coverage>` block, or move it to a separate
  `phpunit-coverage.xml`) is deliberately not applied here because `phpunit.xml`
  is one of the files carrying the abandoned uncommitted work-in-progress
  described above, and editing it would entangle the two changes.~~
  Resolved, in two steps: the `<coverage>` block was already dropped by the
  WIP itself when that landed (`bf-4gq`), removing the warning, and the
  suite was then partitioned so the retired subprocess cases report skips
  instead of fataling against the wrong class — `./vendor/bin/phpunit` now
  exits zero on a plain `composer install` checkout, which is what
  `scripts/definition-of-done.sh` gates on (`pdfphp-3c3f44af`, commit
  `f0a1d6f`). The entanglement that deferred the one-line fix is gone with
  the WIP it referred to.
- ~~`Client::extractText()`, `extractMarkdown()`, `extractStream()`,
  `search()`, and `verifyReceipt()` each hand-roll their own ~30-line
  `proc_open`/pipe-handling block instead of reusing the private `exec()`
  helper that `extract()`, `getMetadata()`, `hash()`, and `classify()`
  already share — six near-identical copies of the same subprocess logic.~~
  Resolved by the ADR-1 landing rather than by deduplication: the six
  copies lived in the subprocess client, which is retired. The canonical
  HTTP `Client` (`src/Client.php`) builds every request through one shared
  request/transfer/error path — the deduplication ADR-1 promised, achieved
  by construction — and the subprocess code survives only as the retired
  record (`tests/Retired/`) and the pending-deletion `src/Pdftract/` tree
  (`pdfphp-ce2bbc56`).
- ~~No timeout is set on any `proc_open()` call — a hung or slow `pdftract`
  invocation (e.g. a pathological PDF) blocks the calling PHP process
  indefinitely.~~ Fixed 2026-07-30 (bead `bf-jyj`): every subprocess is now
  bounded by a configurable timeout enforced with `stream_select()` +
  `proc_terminate()`, and stdout/stderr are drained concurrently (a child
  filling the stderr pipe buffer used to deadlock the parent outright).
  Buffered calls get a total wall-clock bound; `extractStream()`/`search()`
  get an idle bound. When ADR-1's HTTP transport lands, the equivalent
  (`CURLOPT_TIMEOUT` / `CURLOPT_CONNECTTIMEOUT`) must carry the same defaults
  and the same `timeout` per-call option. That carry-over has since
  happened: the HTTP client bounds buffered calls with
  `CURLOPT_TIMEOUT_MS` + `CURLOPT_CONNECTTIMEOUT_MS` and streaming calls
  with an idle bound that resets on server output, with the same
  constructor-configurable default (`DEFAULT_TIMEOUT_SECONDS` = 300.0) and
  the same per-call `'timeout'` option (`src/Client.php` class docblock).
  The subprocess client's second default
  (`DEFAULT_QUICK_TIMEOUT_SECONDS` = 15.0 for metadata/hash-class calls)
  had no HTTP method left to apply to — all three HTTP methods are
  extract-class — so the single 300.0 default covers the whole HTTP
  surface. Note the retired subprocess code this bullet was written against
  lives on only as the `tests/Retired/` record and the pending-deletion
  `src/Pdftract/` tree.
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
- The model/exception tree (`src/Models/` plus the exception hierarchy at
  the `src/` root) is kept — it's a reasonable representation of the API's
  data shapes — but verified against what `pdftract --serve` actually
  exposes, not the CLI's JSON output shape, since the two are not
  guaranteed identical. The original wording — regenerate/audit "against
  the actual HTTP OpenAPI schema `pdftract --serve` exposes" — is void: at
  pinned upstream revision `eeab77e` no such schema exists, the serve API
  being a hand-routed axum `Router` with no OpenAPI-generating crate
  (`docs/notes/serve-parity-gap.md` § "OpenAPI finding"), and following it
  would mean fabricating a schema rather than generating against one
  (`docs/notes/codegen-stubs-resolution.md`, which resolved the `Codegen`
  stubs by deletion on that basis). Verification rests on what does exist:
  the serve-route parity gap matrix in `docs/notes/serve-parity-gap.md`
  with its 2026-09-26 `markdown_anchors` and 2026-09-27 route-parity
  empirical addenda, plus the request-shape pins in
  `tests/ClientBufferedRouteTest.php` and `tests/ClientStreamingRouteTest.php`.
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

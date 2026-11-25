# The binary conformance harness — variants, the `PDFTRACT_BIN` gate, and the DoD gate

- **Date:** 2026-11-24 (consolidates the state introduced by `0672ddd`,
  2026-09-23, and the 2026-09-29 retirement)
- **Status:** developer documentation. The build script
  (`scripts/build-conformance-binaries.sh`) and the DoD gate
  (`scripts/definition-of-done.sh`) are live; the `PDFTRACT_BIN`-gated
  suites they feed are retired records under `tests/Retired/` since
  `b18ec27` (bead `pdfphp-62319135`), kept as the contract for whoever
  re-lands a binary conformance harness on the canonical HTTP client
  (open beads: "Audit and land the PDFTRACT_BIN-gated binary conformance
  harness…", "Add a real-binary conformance harness that runs Client
  methods against vendored fixtures"). The README's Development section
  carries the short form of this note.

This note exists because the task that commissioned it was written while
`tests/ClientBinaryConformanceTest.php` and
`tests/Support/conformance-runner.php` were "being landed" — five days
before they were retired into `tests/Retired/` along with the
CLI-subprocess transport they drove. Everything else the task names still
exists and is documented here as it behaves today.

## Why there are two binary variants

The SDK's methods shell out (historically) to the real `pdftract` CLI, and
the one CLI surface any covered method needs that is feature-gated
upstream is the `grep` subcommand (`search()` → `pdftract grep …`).
`classify()` targets the ungated `classify` subcommand, so it runs against
a default build. The harness therefore runs against two variants built
from the same upstream checkout:

- **`pdftract-default`** — the upstream crate's default cargo feature set,
  which is empty: a typical release build
  (`cargo build --release -p pdftract-cli`).
- **`pdftract-full`** — feature-complete:
  `cargo build --release -p pdftract-cli --features profiles,grep`.

Both build in ONE cargo target dir in the upstream checkout so the
dependency graph compiles once; the finished binaries are copied aside per
variant because the second build overwrites `target/release/pdftract`.
They land in `<upstream>/target/conformance/` — the upstream checkout's
gitignored `target/`, so nothing is written into pdftract-php's tree.

## Building the variants

```bash
scripts/build-conformance-binaries.sh
```

`PDFTRACT_REPO` points at the upstream checkout (default: the `../pdftract`
sibling of this repo). On success the script prints the two export lines
the harness reads:

```
export PDFTRACT_BIN=<repo>/target/conformance/pdftract-default
export PDFTRACT_BIN_FULL=<repo>/target/conformance/pdftract-full
```

Behaviour worth knowing before the first run:

- **Fails fast on git-archive extractions.** The upstream `.gitignore`'s
  generic `build/` pattern also swallows the crate's own build-data
  directories (`crates/pdftract-core/build/` and the repo-root `build/`).
  They are untracked, so a `git archive HEAD | tar -x` extraction ships
  without them and pdftract-core's `build.rs` aborts on the missing
  `CHECKSUMS.sha256`. The script detects exactly this and prints the seed
  recipe (`cp -r <checkout>/build …`, twice) instead of a confusing cargo
  failure.
- **Warns loudly on a dirty upstream tree.** The checkout may carry
  uncommitted work from other fleet agents; the script records the exact
  rev it built from (`PDFTRACT_REV` covers `.git`-less extractions, where
  `git rev-parse` cannot answer) and says so if the binaries are "rev PLUS
  local edits", not a clean build. Prefer a pristine extraction.
- **Feature fallback.** The full variant asks for `profiles,grep`
  (`PDFTRACT_CONFORMANCE_FEATURES`). Upstream caveat (checked 2026-09-23):
  `profiles` does not compile at upstream HEAD
  (`crates/pdftract-cli/src/profiles_cmd.rs` references undefined values,
  skips imports, reaches into core's private modules). When that build
  fails the script falls back to `grep` alone
  (`PDFTRACT_CONFORMANCE_FALLBACK_FEATURES`) — which covers every
  feature-gated CLI surface the SDK's methods exercise — with a loud
  warning. Set `PDFTRACT_CONFORMANCE_FEATURES` to a list that compiles to
  skip the doomed attempt.
- **Smoke gate.** Each copied binary must execute `--help` (not
  `--version`: upstream declares no `--version` flag at `eeab77e`, clap
  rejects it with exit 2) or the script fails — a stale, executable,
  non-runnable copy is worse than a missing one.

The serve-capable variant is a *different* binary from a *different*
script: `scripts/build-serve-bin.sh` (see
`docs/notes/serve-parity-gap.md`, Addendum 2026-09-27e) builds current
upstream, refuses it unless `GET /health` answers, and feeds
`PDFTRACT_SERVE_BIN`, not `PDFTRACT_BIN`. The stock conformance binaries
cannot serve (ConnectInfo defect, Addendum 2026-09-27c) — do not
cross-wire the two variables.

## The `PDFTRACT_BIN` gate and clean-skip semantics

The contract as the binary conformance suites carried it — `tests/Retired/
ClientBinaryConformanceTest.php` (the per-method outcome sweep) and
`tests/Retired/ClientHashConformanceTest.php` (the hash pins) — and as a
re-landed harness is expected to reuse it:

- **`PDFTRACT_BIN` unset** → every binary conformance case skips, with a
  message naming the variable and pointing at
  `scripts/build-conformance-binaries.sh`. A clean skip, never a failure.
- **`PDFTRACT_BIN` set but not an executable file** → the case FAILS
  loudly (`"PDFTRACT_BIN is set but not an executable file: …"`). A
  misconfigured path must not masquerade as a skip.
- **`PDFTRACT_BIN_FULL` unset** → only the feature-gated cases skip
  (search, whose CLI surface is the `grep` subcommand); every
  ungated-surface case (hash's control, classify, …) still runs against
  `PDFTRACT_BIN`. The skip message notes the missing feature build.
- **Plain-suite green with no binary at all.** Both suites sat behind the
  `binary-conformance` group; since the retirement they additionally live
  in the `retired-cli-subprocess` group, which `phpunit.xml` excludes from
  the default suite, and every case self-skips in `setUp()` before any
  body runs. `vendor/bin/phpunit` and the DoD gate therefore never need a
  binary.

The two LIVE binary-gated suites key on a different variable
(`PDFTRACT_SERVE_BIN`, groups `serve-parity` and `real-server`) and skip
with a message under the same discipline when it is unset. Because a clean
skip is indistinguishable from a suite that silently never ran, CI runs
`scripts/ci-assert-gated-ran.php` on the gated invocation's junit log: a
CI run that loses its binary fails the build instead of passing green.
The template's `--group` list still names `binary-conformance`, which now
matches nothing; the retired pair's executed-floor assertions went with
the retirement.

## Running the definition-of-done gate locally

```bash
scripts/definition-of-done.sh          # the license gate + the PHPUnit suite, green
scripts/definition-of-done.sh --fast   # the quick gate CI asks for
```

What the script does, in order:

1. **Resolves `php`.** Plain `php` on the lab is the nix-store default and
   lacks the curl extension the client's tests need, so the script falls
   back to the first `php-with-extensions` profile under `/nix/store` when
   `php` is not on PATH (or fails with that diagnosis).
2. **Runs the license gate.** Asserts the MIT claim before the suite:
   LICENSE present at the repo root, opening with the standard title,
   carrying a real `Copyright (c) <year> <holder>` line, the standard
   grant text verbatim past the copyright, and `composer.json`'s
   `license` field agreeing. `composer validate` does not check for the
   file, and a missing or divergent LICENSE is a silent blocker for the
   Packagist publication path (bf-64f).
3. **Resolves `composer`.** PATH first, then the nix-store composer
   profile; when both are missing (the profile has been garbage-collected
   on this box before) **and** `vendor/autoload.php` is absent, it
   bootstraps a checksum-pinned composer 2.11.3 phar into a temp dir and
   runs it through the php resolved above. CI's builder image carries
   composer and never reaches this path.
4. **Installs dependencies** (`composer install --no-interaction
   --no-progress`) only when `vendor/` is missing.
5. **Execs `php vendor/bin/phpunit`** with any extra arguments forwarded.

`--fast` is consumed rather than forwarded — PHPUnit rejects unknown
options, so passing it through would fail the very quick gate it asks
for — and selects nothing today because the suite has no slow partition.

Gated suites skip cleanly without their binaries (see above), so a plain
green run needs no binary at all. Baseline on HEAD as of 2026-11-24:
162 tests, 884 assertions, 20 gated skips, exit 0 (evidence on
`pdfphp-8d39714a`).

## Re-landing path

`docs/notes/adr1-serve-parity-memo.md` ("Conformance suite") holds the
decision: the two retired suites un-retire and rejoin the gated set under
`PDFTRACT_BIN` when a CLI-binary build leg exists in CI
(`scripts/build-conformance-binaries.sh` already does), and
`ci-assert-gated-ran.php` must then cover the resurrected groups. The DoD's
plain run stays green either way — the `retired-cli-subprocess` partition
is excluded by default, and the gate's semantics accept a suite that skips
cleanly.

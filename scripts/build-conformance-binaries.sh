#!/usr/bin/env bash
#
# Build the two pdftract CLI variants the binary conformance harness runs
# against (tests/ClientBinaryConformanceTest.php):
#
#   pdftract-default — default cargo features (the upstream crate's default
#                      set is empty: a typical release build)
#   pdftract-full    — feature-complete: --features profiles,grep
#
# Both build from the upstream pdftract checkout in ONE cargo target dir so
# the dependency graph compiles once; the finished binaries are copied aside
# per variant because the second build overwrites target/release/pdftract.
#
# The only CLI surface the SDK's methods need that is feature-gated is the
# `grep` subcommand (search() → `pdftract grep ...`). classify() targets the
# ungated `classify` subcommand, so it runs against the default binary too.
#
# Upstream caveat (checked 2026-09-23): the `profiles` feature does NOT
# compile at the upstream HEAD — crates/pdftract-cli/src/profiles_cmd.rs
# references undefined values (`path` vs `_path`, `name`, `name_or_path`),
# uses `fs::`/`Context` without importing them, and reaches into core's
# private `profiles::extraction_loader` module. When the profiles build
# fails, the script falls back to `grep` alone (everything the harness
# needs) with a loud warning; set PDFTRACT_CONFORMANCE_FEATURES to a list
# that compiles to skip the failed attempt.
#
# Second upstream caveat (hit 2026-09-23): the upstream .gitignore's generic
# `build/` pattern also swallows the crate's own build-data directories
# (crates/pdftract-core/build/ and the repo-root build/). They are
# untracked, so a `git archive HEAD` extraction ships without them and
# pdftract-core's build.rs aborts on the missing CHECKSUMS.sha256. The
# script fails fast on that below; seed the extraction from a working
# checkout first:
#   cp -r <checkout>/build                      <extraction>/build
#   cp -r <checkout>/crates/pdftract-core/build <extraction>/crates/pdftract-core/build
#
# Environment:
#   PDFTRACT_REPO                  upstream checkout
#                                  (default: the ../pdftract sibling of this repo)
#   PDFTRACT_REV                   upstream rev to record in the output when
#                                  PDFTRACT_REPO is a .git-less extraction
#                                  (default: `git rev-parse HEAD` of PDFTRACT_REPO)
#   PDFTRACT_CONFORMANCE_FEATURES  feature list for the full variant
#                                  (default: profiles,grep)
#   PDFTRACT_CONFORMANCE_FALLBACK_FEATURES
#                                  feature list used when the primary list
#                                  fails to build (default: grep)
#
# On success the script prints the two env vars the harness reads:
#
#   export PDFTRACT_BIN=<...>/pdftract-default
#   export PDFTRACT_BIN_FULL=<...>/pdftract-full
#
# Nothing here writes into pdftract-php's tree: the binaries live under the
# upstream checkout's (gitignored) target/ directory.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PDFTRACT_REPO="${PDFTRACT_REPO:-$(dirname "$REPO_ROOT")/pdftract}"
FEATURES="${PDFTRACT_CONFORMANCE_FEATURES:-profiles,grep}"
FALLBACK_FEATURES="${PDFTRACT_CONFORMANCE_FALLBACK_FEATURES:-grep}"
OUT_DIR="$PDFTRACT_REPO/target/conformance"

if [[ ! -f "$PDFTRACT_REPO/Cargo.toml" ]]; then
    echo "error: no upstream pdftract checkout at $PDFTRACT_REPO" >&2
    echo "       set PDFTRACT_REPO to the pdftract repository and re-run" >&2
    exit 1
fi

if [[ ! -f "$PDFTRACT_REPO/crates/pdftract-core/build/CHECKSUMS.sha256" ]]; then
    echo "error: $PDFTRACT_REPO is missing crates/pdftract-core/build/ —" >&2
    echo "       the upstream .gitignore's generic 'build/' pattern leaves that" >&2
    echo "       build-data directory untracked, so git-archive extractions ship" >&2
    echo "       without it and pdftract-core's build.rs aborts on the missing" >&2
    echo "       CHECKSUMS.sha256. Seed the extraction from a working checkout" >&2
    echo "       and re-run:" >&2
    echo "         cp -r <checkout>/build $PDFTRACT_REPO/build" >&2
    echo "         cp -r <checkout>/crates/pdftract-core/build $PDFTRACT_REPO/crates/pdftract-core/build" >&2
    exit 1
fi

# What the binaries are built FROM. The checkout may carry uncommitted work
# from other fleet agents, so record the exact rev the output corresponds to
# (a dirty tree makes the binary "rev + local edits" — say so loudly).
# PDFTRACT_REV covers the pristine-extraction case: a `git archive` tree has
# no .git for rev-parse to read, so pass the rev the archive was made from.
UPSTREAM_REV="${PDFTRACT_REV:-$(git -C "$PDFTRACT_REPO" rev-parse HEAD 2>/dev/null || echo unknown)}"
if [[ -n "$(git -C "$PDFTRACT_REPO" status --porcelain -- crates Cargo.toml Cargo.lock 2>/dev/null)" ]]; then
    echo "warning: $PDFTRACT_REPO has uncommitted changes under crates/ —" >&2
    echo "         these binaries are $UPSTREAM_REV PLUS local edits, not a" >&2
    echo "         clean build of that rev. Prefer a pristine extraction" >&2
    echo "         (git archive HEAD | tar -x -C <dir>, then PDFTRACT_REPO=<dir>," >&2
    echo "         seeded with the two build-data directories — see the header)." >&2
fi

echo "==> building pdftract-default (default features) from $PDFTRACT_REPO @ $UPSTREAM_REV"
(cd "$PDFTRACT_REPO" && cargo build --release -p pdftract-cli)
mkdir -p "$OUT_DIR"
cp "$PDFTRACT_REPO/target/release/pdftract" "$OUT_DIR/pdftract-default"

echo "==> building pdftract-full (--features $FEATURES) from $PDFTRACT_REPO"
FULL_FEATURES="$FEATURES"
if (cd "$PDFTRACT_REPO" && cargo build --release -p pdftract-cli --features "$FEATURES"); then
    cp "$PDFTRACT_REPO/target/release/pdftract" "$OUT_DIR/pdftract-full"
else
    echo "" >&2
    echo "warning: --features $FEATURES does not compile at this upstream checkout;" >&2
    echo "         falling back to --features $FALLBACK_FEATURES, which covers every" >&2
    echo "         feature-gated CLI surface the SDK's methods exercise (grep)." >&2
    echo "" >&2
    echo "==> building pdftract-full (--features $FALLBACK_FEATURES) from $PDFTRACT_REPO"
    FULL_FEATURES="$FALLBACK_FEATURES"
    (cd "$PDFTRACT_REPO" && cargo build --release -p pdftract-cli --features "$FALLBACK_FEATURES")
    cp "$PDFTRACT_REPO/target/release/pdftract" "$OUT_DIR/pdftract-full"
fi

for binary in "$OUT_DIR/pdftract-default" "$OUT_DIR/pdftract-full"; do
    if [[ ! -x "$binary" ]]; then
        echo "error: expected binary $binary is missing or not executable" >&2
        exit 1
    fi
    # Smoke gate: a stale/corrupt copy that is executable but cannot run is
    # worse than a missing one — fail here rather than downstream in the
    # harness. The probe is --help, not --version: upstream declares no
    # --version flag at eeab77e (clap rejects it, exit 2).
    if ! "$binary" --help >/dev/null 2>&1; then
        echo "error: $binary --help failed; the copy is not runnable" >&2
        exit 1
    fi
done

echo
echo "Both variants built (upstream $UPSTREAM_REV). Point the harness at them:"
echo "  export PDFTRACT_BIN=$OUT_DIR/pdftract-default"
echo "  export PDFTRACT_BIN_FULL=$OUT_DIR/pdftract-full"
echo "# pdftract-default: default features; pdftract-full: --features $FULL_FEATURES"

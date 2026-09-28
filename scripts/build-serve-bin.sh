#!/usr/bin/env bash
#
# Build the pdftract binary the serve-gated test groups run against
# (tests/ClientServeParityTest.php, group serve-parity, and
# tests/ClientRealServerTest.php, group real-server — both read
# PDFTRACT_SERVE_BIN).
#
# Why this script exists: at the conformance-work pinned revision
# (eeab77e) EVERY stock pdftract build answers HTTP 500 on every serve
# request, including GET /health — the audit middleware's ConnectInfo
# extractor panics because the router is served through a bare
# into_make_service() (docs/notes/serve-parity-gap.md, Addendum
# 2026-09-27c). Upstream has since fixed that service construction at
# both axum::serve call sites (and the stderr-only error-chain
# reporting the serve-parity pins read), so a plain release build of a
# current revision is serve-capable with no source patch — the fix and
# the first verified build are recorded in that file's Addendum
# 2026-09-27e. This script builds exactly that and REFUSES to hand over
# a binary whose serve surface is broken: the /health smoke gate below
# is the same gate ClientRealServerTest applies, so a repeat of the
# eeab77e defect fails here, loudly, instead of shipping a binary every
# suite skips around.
#
#   scripts/build-serve-bin.sh
#   export PDFTRACT_SERVE_BIN=<printed path>
#   php vendor/bin/phpunit --group serve-parity
#   php vendor/bin/phpunit --group real-server
#
# The build never touches the checkout's working tree: the revision is
# extracted with `git archive` into a scratch dir under the checkout's
# (gitignored) target/, so another fleet agent's uncommitted work can
# never end up in the binary. Since the pinned conformance revision the
# build-data directories under crates/pdftract-core/build/ are tracked
# upstream, so a git-archive extraction is self-contained — no seeding.
#
# Environment:
#   PDFTRACT_REPO      upstream checkout (default: the ../pdftract
#                      sibling of this repo)
#   PDFTRACT_REV       revision to build (default: origin/main; falls
#                      back to HEAD when the checkout has no origin/main)
#   PDFTRACT_BUILD_DIR extraction/build scratch dir
#                      (default: $PDFTRACT_REPO/target/serve-build;
#                      kept across runs so cargo increments)
#   PDFTRACT_SERVE_OUT where the smoke-gated binary is installed
#                      (default: $PDFTRACT_REPO/target/serve-capable/
#                      pdftract-serve-capable)
#   PDFTRACT_KEEP_BUILD=1  keep the extraction dir on failure (it is
#                      always reused in place, never deleted on success)
#
# On success the script prints the export line the harness reads plus
# the built revision and sha256 — record both when a verdict cites the
# binary.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PDFTRACT_REPO="${PDFTRACT_REPO:-$(dirname "$REPO_ROOT")/pdftract}"

if [[ ! -d "$PDFTRACT_REPO/.git" ]]; then
    echo "error: $PDFTRACT_REPO is not a git checkout — this script builds" >&2
    echo "       from `git archive`, so it needs one (set PDFTRACT_REPO)." >&2
    exit 1
fi

PDFTRACT_REV="${PDFTRACT_REV:-}"
if [[ -z "$PDFTRACT_REV" ]]; then
    if git -C "$PDFTRACT_REPO" rev-parse --verify --quiet origin/main >/dev/null; then
        PDFTRACT_REV=origin/main
    else
        PDFTRACT_REV=HEAD
    fi
fi

if ! UPSTREAM_SHA="$(git -C "$PDFTRACT_REPO" rev-parse "$PDFTRACT_REV^{commit}")"; then
    echo "error: cannot resolve $PDFTRACT_REV in $PDFTRACT_REPO" >&2
    exit 1
fi

BUILD_DIR="${PDFTRACT_BUILD_DIR:-$PDFTRACT_REPO/target/serve-build}"
OUT_BIN="${PDFTRACT_SERVE_OUT:-$PDFTRACT_REPO/target/serve-capable/pdftract-serve-capable}"

echo "==> extracting $PDFTRACT_REPO @ $UPSTREAM_SHA ($PDFTRACT_REV) into $BUILD_DIR"
mkdir -p "$BUILD_DIR"
git -C "$PDFTRACT_REPO" archive "$UPSTREAM_SHA" | tar -x -C "$BUILD_DIR"

if [[ ! -f "$BUILD_DIR/crates/pdftract-core/build/CHECKSUMS.sha256" ]]; then
    echo "error: the extraction is missing crates/pdftract-core/build/ —" >&2
    echo "       this revision predates the tracking of those build-data" >&2
    echo "       files, and pdftract-core's build.rs aborts without them." >&2
    echo "       Build a revision that tracks them (see the conformance" >&2
    echo "       script for the manual seeding recipe if you truly need" >&2
    echo "       an older revision)." >&2
    exit 1
fi

echo "==> cargo build --release -p pdftract-cli (upstream $UPSTREAM_SHA)"
(cd "$BUILD_DIR" && cargo build --release -p pdftract-cli)

BUILT="$BUILD_DIR/target/release/pdftract"
if [[ ! -x "$BUILT" ]]; then
    echo "error: expected $BUILT after the build" >&2
    exit 1
fi

# Runnable gate. --help, not --version: the eeab77e-era binaries clap-reject
# --version with exit 2 (newer revisions accept it), and --help works across
# the whole range — the gate should not care which revision was built.
if ! "$BUILT" --help >/dev/null 2>&1; then
    echo "error: $BUILT --help failed; the build is not runnable" >&2
    exit 1
fi

# The serve-capability gate: start the server exactly the way the test
# suites do (loopback, ephemeral port, --no-cache) and require GET
# /health to answer HTTP 200. A binary with the eeab77e-era ConnectInfo
# defect starts, prints its banner, and then 500s every request — this
# gate is what keeps such a binary from ever being handed to
# PDFTRACT_SERVE_BIN.
SMOKE_PORT_FILE="$(mktemp)"
SMOKE_OUT="$(mktemp)"
SMOKE_ERR="$(mktemp)"
cleanup_smoke() {
    if [[ -n "${SMOKE_PID:-}" ]] && kill -0 "$SMOKE_PID" 2>/dev/null; then
        kill "$SMOKE_PID" 2>/dev/null || true
        wait "$SMOKE_PID" 2>/dev/null || true
    fi
    rm -f "$SMOKE_PORT_FILE" "$SMOKE_OUT" "$SMOKE_ERR"
}
trap cleanup_smoke EXIT

SMOKE_PORT="$(python3 -c '
import socket
s = socket.socket()
s.bind(("127.0.0.1", 0))
print(s.getsockname()[1])
s.close()
')"

"$BUILT" serve --bind "127.0.0.1:$SMOKE_PORT" --no-cache >"$SMOKE_OUT" 2>"$SMOKE_ERR" &
SMOKE_PID=$!

SMOKE_OK=0
for _ in $(seq 1 150); do # 150 x 100ms = the suites' 15s health budget
    if ! kill -0 "$SMOKE_PID" 2>/dev/null; then
        echo "error: serve exited during the smoke gate; stderr:" >&2
        cat "$SMOKE_ERR" >&2
        exit 1
    fi
    SMOKE_STATUS="$(curl -s -o /dev/null -w '%{http_code}' \
        --max-time 1 "http://127.0.0.1:$SMOKE_PORT/health" || true)"
    if [[ "$SMOKE_STATUS" == "200" ]]; then
        SMOKE_OK=1
        break
    fi
    sleep 0.1
done

if [[ "$SMOKE_OK" != "1" ]]; then
    echo "error: GET /health never answered 200 within 15s (last status:" >&2
    echo "       ${SMOKE_STATUS:-none}) — this binary is NOT serve-capable;" >&2
    echo "       stderr so far:" >&2
    cat "$SMOKE_ERR" >&2
    exit 1
fi

cleanup_smoke
trap - EXIT

mkdir -p "$(dirname "$OUT_BIN")"
cp "$BUILT" "$OUT_BIN"

SHA256="$(sha256sum "$OUT_BIN" | cut -d' ' -f1)"
echo
echo "Serve-capable binary installed (upstream $UPSTREAM_SHA)."
echo "  sha256: $SHA256"
echo "Point the serve-gated groups at it:"
echo "  export PDFTRACT_SERVE_BIN=$OUT_BIN"

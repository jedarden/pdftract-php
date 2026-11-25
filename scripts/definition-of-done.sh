#!/usr/bin/env bash
#
# The repo's definition of done: the PHPUnit suite, green.
#
#   scripts/definition-of-done.sh [--fast]
#
# --fast selects nothing today (the suite has no slow partition) and is
# consumed here rather than forwarded: PHPUnit rejects unknown options, so
# passing it through would fail the very quick gate it asks for. The suites
# gated on a serve-capable pdftract binary (groups serve-parity and
# real-server, env PDFTRACT_SERVE_BIN) skip with a message when it is unset,
# and the retired CLI-subprocess suites under tests/Retired/ are excluded
# from the default suite entirely — a plain green run needs no binary.
#
# On machines where php is not on PATH (the lab's nix store), the script
# falls back to the known php-with-extensions profile: plain `php` there
# lacks the curl extension the canonical client's tests need. composer gets
# the same treatment — a clean clone has no vendor/, and the composer install
# below needs a working composer even though PATH doesn't carry one. When the
# composer lookup fails on both PATH and the nix store (the profile has been
# garbage-collected from this box before, which left clean extractions with
# no way to provision vendor/ at all), a last-resort bootstrap downloads a
# checksum-pinned composer phar into a temp dir and runs it through the php
# already resolved above; CI's builder image carries composer and never
# reaches this path.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

if ! command -v php >/dev/null 2>&1; then
    for candidate in /nix/store/*-php-with-extensions-*/bin/php; do
        if [[ -x "$candidate" ]]; then
            PATH="$(dirname "$candidate"):$PATH"
            export PATH
            break
        fi
    done
fi

if ! command -v php >/dev/null 2>&1; then
    echo "error: php not found on PATH (and no nix php-with-extensions profile present)" >&2
    exit 1
fi

if ! command -v composer >/dev/null 2>&1; then
    for candidate in /nix/store/*-composer-*/bin/composer; do
        if [[ -x "$candidate" ]]; then
            PATH="$(dirname "$candidate"):$PATH"
            export PATH
            break
        fi
    done
fi

# composer, third fallback: no PATH binary and no nix profile (e.g. after a
# nix store GC). Bootstrap a pinned phar rather than failing — a clean clone
# has no vendor/ to fall back on. Version and checksum are pinned to the
# release; https://getcomposer.org/download/2.11.3/composer.phar.sha256sum
# publishes the digest this pins.
COMPOSER=(composer)
if ! command -v composer >/dev/null 2>&1 && [[ ! -f vendor/autoload.php ]]; then
    PHAR_DIR="$(mktemp -d)"
    trap 'rm -rf "$PHAR_DIR"' EXIT
    echo "==> no composer on PATH or in the nix store; bootstrapping the pinned composer 2.11.3 phar"
    if ! curl -fsSL --max-time 120 -o "$PHAR_DIR/composer.phar" \
            https://getcomposer.org/download/2.11.3/composer.phar; then
        echo "error: composer phar download failed; cannot install vendor/" >&2
        exit 1
    fi
    echo "04f8b003da4e3a2459056591fbfebd02b4dc3e5adeb158616b90cf55a65113ec  $PHAR_DIR/composer.phar" \
        | sha256sum --check --status || {
        echo "error: composer phar checksum mismatch; refusing to run it" >&2
        exit 1
    }
    COMPOSER=(php "$PHAR_DIR/composer.phar")
fi

if [[ ! -f vendor/autoload.php ]]; then
    echo "==> vendor/ missing; running composer install"
    "${COMPOSER[@]}" install --no-interaction --no-progress
fi

ARGS=()
for arg in "$@"; do
    if [[ "$arg" == "--fast" ]]; then continue; fi
    ARGS+=("$arg")
done

exec php vendor/bin/phpunit "${ARGS[@]}"

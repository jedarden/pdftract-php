#!/usr/bin/env bash
#
# The repo's definition of done: the PHPUnit suite, green.
#
#   scripts/definition-of-done.sh [--fast]
#
# --fast selects nothing today (the suite has no slow partition) and is
# consumed here rather than forwarded: PHPUnit rejects unknown options, so
# passing it through would fail the very quick gate it asks for. Tests that
# need a real pdftract binary (tests/ClientBinaryConformanceTest.php, group
# binary-conformance) skip with a message unless PDFTRACT_BIN is set — a
# plain green run needs no binary installed.
#
# On machines where php is not on PATH (the lab's nix store), the script
# falls back to the known php-with-extensions profile: plain `php` there
# lacks the curl extension the canonical client's tests need. composer gets
# the same treatment — a clean clone has no vendor/, and the composer install
# below needs a working composer even though PATH doesn't carry one.

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

if ! command -v composer >/dev/null 2>&1 && [[ ! -f vendor/autoload.php ]]; then
    echo "error: composer not found on PATH (and no nix composer profile present); cannot install vendor/" >&2
    exit 1
fi

if [[ ! -f vendor/autoload.php ]]; then
    echo "==> vendor/ missing; running composer install"
    composer install --no-interaction --no-progress
fi

ARGS=()
for arg in "$@"; do
    if [[ "$arg" == "--fast" ]]; then continue; fi
    ARGS+=("$arg")
done

exec php vendor/bin/phpunit "${ARGS[@]}"

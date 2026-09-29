<?php

declare(strict_types=1);

/**
 * CI-side guard: assert the binary-gated groups RAN instead of skipping.
 *
 * The ADR-1 transport evidence lives in two suites that skip CLEANLY when
 * the serve-capable pdftract binary is not configured — so cleanly that a
 * CI run which loses them still exits 0 ("OK, but some tests were
 * skipped!"):
 *
 * - tests/ClientServeParityTest.php        (group serve-parity,
 *   PDFTRACT_SERVE_BIN)
 * - tests/ClientRealServerTest.php         (group real-server,
 *   PDFTRACT_SERVE_BIN — plus the class's own GET /health gate)
 *
 * A third pair once sat here — tests/ClientBinaryConformanceTest.php and
 * tests/ClientHashConformanceTest.php (group binary-conformance), the
 * CLI-subprocess comparison leg — but both were retired into tests/Retired/
 * with the transport they drove (bead pdfphp-62319135). No live test
 * carries `binary-conformance` any more; the pdftract-php-ci template still
 * names the group in its --group list, where it now matches nothing, and
 * the retired pair's floors (38 / 4 executed, one tolerated remote-fixture
 * skip) went with them.
 *
 * The pdftract-php-ci WorkflowTemplate runs the gated groups as their own
 * phpunit invocation with --log-junit and then calls this script on the
 * log; a non-zero exit fails the build. Run it on the GATED invocation's
 * log only — the plain full-suite run legitimately carries by-design skips
 * (the pending-no-serve-route partition) that would trip nothing here but
 * would also prove nothing: its junit is not evidence the transport ran.
 *
 * Why the checks are structural rather than message-matching: PHPUnit 10's
 * junit log emits a bare <skipped/> element with no message attribute, so
 * "did the env-gating skip message appear" is undetectable from the log.
 * Instead every gated class must show up with no skipped testcases — an
 * unset PDFTRACT_SERVE_BIN zeroes a class entirely (setUpBeforeClass
 * gates), and that shape is caught by the per-class rules below.
 *
 * max_skips is zero everywhere: both remaining suites gate their whole
 * class on the environment, so any skip in the gated log means a case ran
 * without the transport. A new by-design skip should raise its class's
 * ceiling, not slip past it.
 *
 * Usage:
 *   php vendor/bin/phpunit --group 'serve-parity,real-server' \
 *       --log-junit /tmp/junit-gated.xml
 *   php scripts/ci-assert-gated-ran.php /tmp/junit-gated.xml
 */

if ($argc !== 2) {
    fwrite(STDERR, "usage: php scripts/ci-assert-gated-ran.php <junit.xml>\n");
    exit(2);
}

$path = $argv[1];
if (!is_file($path)) {
    fwrite(STDERR, "error: junit log not found: {$path}\n");
    exit(2);
}

$log = @simplexml_load_file($path);
if ($log === false) {
    fwrite(STDERR, "error: cannot parse junit log: {$path}\n");
    exit(2);
}

/**
 * Per-class pass rules for the gated invocation's junit log.
 *
 * min_executed — floor of executed (non-skipped) testcases, measured
 *                2026-09-27 against a serve-capable upstream build
 *                (executed: 36 / 12). The floors sit well below those
 *                counts; they catch a group that collected nothing, while
 *                max_skips below does the env-gating detection.
 * max_skips    — ceiling of skipped testcases. Zero everywhere.
 */
const GATED_CLASSES = [
    'Jedarden\Pdftract\Tests\ClientServeParityTest' => ['min_executed' => 1, 'max_skips' => 0],
    'Jedarden\Pdftract\Tests\ClientRealServerTest' => ['min_executed' => 1, 'max_skips' => 0],
];

$counts = [];
foreach ($log->xpath('//testcase') as $testcase) {
    $class = (string) ($testcase['class'] ?? '');
    if (!isset(GATED_CLASSES[$class])) {
        continue;
    }
    $counts[$class]['total'] = ($counts[$class]['total'] ?? 0) + 1;
    if (isset($testcase->skipped)) {
        $counts[$class]['skipped'] = ($counts[$class]['skipped'] ?? 0) + 1;
    } else {
        $counts[$class]['executed'] = ($counts[$class]['executed'] ?? 0) + 1;
    }
}

// Count testcase elements, not the root testsuite's `tests` attribute:
// PHPUnit 10 only emits `tests` on the CLASS-level testsuites — the root
// `<testsuites><testsuite>` hierarchy carries none, so reading it yields 0
// for a perfectly healthy log and the guard would fail every run.
$collected = count($log->xpath('//testcase'));
if ($collected === 0) {
    fwrite(STDERR, "FAIL: the junit log holds 0 testcases — the --group list matched nothing.\n");
    exit(1);
}

echo "Gated-run junit log: {$collected} tests collected.\n";

$failures = [];
foreach (GATED_CLASSES as $class => $rule) {
    $short = ($pos = strrpos($class, '\\')) === false ? $class : substr($class, $pos + 1);
    $seen = $counts[$class] ?? [];
    $total = $seen['total'] ?? 0;
    $executed = $seen['executed'] ?? 0;
    $skipped = $seen['skipped'] ?? 0;

    printf(
        "  %-28s collected=%-3d executed=%-3d skipped=%-2d (need executed>=%d, skips<=%d)\n",
        $short,
        $total,
        $executed,
        $skipped,
        $rule['min_executed'],
        $rule['max_skips']
    );

    if ($total === 0) {
        $failures[] = "{$short}: absent from the junit log — its group never matched"
            . ' (check the template\'s --group list).';
        continue;
    }
    if ($executed < $rule['min_executed']) {
        $failures[] = "{$short}: {$executed} executed, below the floor of {$rule['min_executed']}"
            . ' — the suite ran nothing.';
    }
    if ($skipped > $rule['max_skips']) {
        $failures[] = "{$short}: {$skipped} skipped testcases (ceiling {$rule['max_skips']}) —"
            . ' env-gating skips look like passes to phpunit\'s exit code, so this is a'
            . ' coverage hole, not a green result. Check PDFTRACT_SERVE_BIN and the'
            . ' build step\'s smoke gate.';
    }
}

if ($failures !== []) {
    fwrite(STDERR, "\nFAIL: the binary-gated suites did not actually run:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "OK: every binary-gated suite executed — the ADR-1 transport evidence ran in CI.\n";

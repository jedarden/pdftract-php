<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Conformance test suite for the pdftract PHP SDK.
 *
 * The conformance suite (case definitions + input fixtures) is vendored into
 * this repository under tests/sdk-conformance/ so these tests run standalone —
 * without this repo needing to be a subdirectory of a pdftract monorepo. See
 * tests/sdk-conformance/README.md for provenance and re-vendoring steps.
 *
 * These tests validate that the vendored suite is present and well-formed and
 * that every case's fixtures exist and are readable. Executing each case
 * against a live pdftract transport (actual method-behavior conformance) is
 * deferred to the HTTP-client migration (docs/plan/plan.md, ADR-1), which
 * requires a running `pdftract --serve` endpoint.
 */
class ConformanceTest extends TestCase
{
    /** Vendored conformance suite, relative to this file. */
    private const SUITE_PATH = __DIR__ . '/sdk-conformance';
    private const CASES_PATH = self::SUITE_PATH . '/cases.json';
    private const FIXTURES_PATH = self::SUITE_PATH . '/fixtures/';

    /**
     * Decodes the vendored cases.json, failing with a clear message if the
     * vendored suite is missing or malformed.
     */
    private static function loadSuite(): array
    {
        $json = @file_get_contents(self::CASES_PATH);
        if ($json === false) {
            throw new \RuntimeException(
                'Vendored conformance suite not found at ' . self::CASES_PATH
                . '. See tests/sdk-conformance/README.md.'
            );
        }
        $suite = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'Failed to parse conformance cases JSON: ' . json_last_error_msg()
            );
        }
        return $suite;
    }

    public function testSuiteIsWellFormed(): void
    {
        $suite = self::loadSuite();

        $this->assertArrayHasKey('version', $suite, 'cases.json must declare a version');
        $this->assertArrayHasKey('cases', $suite, 'cases.json must declare a cases array');
        $this->assertIsArray($suite['cases']);
        $this->assertNotEmpty($suite['cases'], 'conformance suite must define at least one case');

        $ids = [];
        foreach ($suite['cases'] as $case) {
            $this->assertArrayHasKey('id', $case, 'every case needs an id');
            $this->assertArrayHasKey('method', $case, "case {$case['id']} needs a method");
            $this->assertArrayHasKey('fixture', $case, "case {$case['id']} needs a fixture");
            $this->assertNotContains($case['id'], $ids, "duplicate case id: {$case['id']}");
            $ids[] = $case['id'];
        }
    }

    /**
     * @dataProvider caseProvider
     */
    public function testCaseFixturesArePresent(array $case): void
    {
        $fixture = $case['fixture'];

        // Remote fixtures are fetched over the network at execution time; there
        // is nothing to vendor and nothing to check without a live transport.
        if (str_starts_with($fixture, 'http://') || str_starts_with($fixture, 'https://')) {
            $this->markTestSkipped("Remote fixture (needs network / live transport): {$fixture}");
        }

        $path = self::FIXTURES_PATH . $fixture;
        $this->assertFileExists($path, "Vendored fixture missing for case {$case['id']}: {$fixture}");
        $this->assertFileIsReadable($path);
        $this->assertStringStartsWith(
            '%PDF-',
            (string) file_get_contents($path),
            "Fixture for case {$case['id']} does not look like a PDF: {$fixture}"
        );

        // verify_receipt cases carry a companion receipt file alongside the PDF.
        if (isset($case['options']['receipt'])) {
            $receiptPath = self::FIXTURES_PATH . $case['options']['receipt'];
            $this->assertFileExists(
                $receiptPath,
                "Vendored receipt missing for case {$case['id']}: {$case['options']['receipt']}"
            );
            $this->assertJson(
                (string) file_get_contents($receiptPath),
                "Receipt for case {$case['id']} is not valid JSON"
            );
        }
    }

    /**
     * Yields each conformance case keyed by its id, so a failing case is
     * reported by name.
     *
     * @return iterable<string, array{0: array}>
     */
    public static function caseProvider(): iterable
    {
        foreach (self::loadSuite()['cases'] as $case) {
            if (isset($case['skip_reason'])) {
                continue;
            }
            yield $case['id'] => [$case];
        }
    }
}

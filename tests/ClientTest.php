<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Client cases parked because the serve API has no route for them yet.
 *
 * Partition rationale (2026-09-10): this file used to characterize
 * Jedarden\Pdftract\Client as a subprocess wrapper around a local pdftract
 * binary. ADR-1 (docs/plan/plan.md) retired that transport in favour of
 * `pdftract --serve`, and PSR-4 now resolves Jedarden\Pdftract\Client to the
 * canonical HTTP client, so every subprocess case was fataling against the
 * wrong class. Per bf-4gq the legacy suite was partitioned, not deleted:
 *
 * - This file keeps the cases for the six methods the serve API cannot
 *   reach — search, getMetadata, hash, classify, verifyReceipt, and
 *   extractMarkdown — as explicit pending cases, annotated with bf-4bd and
 *   ADR-1, so the coverage intent stays visible until upstream adds the
 *   routes (bf-4bd). Each case skips itself; none constructs a client or
 *   calls one, because the canonical client deliberately offers none of
 *   these methods.
 * - The remaining subprocess-behaviour cases moved to the superseded
 *   partition under tests/Retired/ (group `retired-cli-subprocess`,
 *   excluded from the default suite in phpunit.xml), retired with the CLI
 *   transport — joined there by tests/verify_psr3_logger.php, which drove
 *   the same transport's logging. They must never be run against the HTTP
 *   client.
 *
 * Neither this file nor tests/ClientTimeoutTest.php references the retired
 * transport or anything under src/Pdftract.
 */
#[Group('pending-no-serve-route')]
class ClientTest extends TestCase
{
    /**
     * Why an individual case is parked: bf-4bd tracks the serve-API routes
     * upstream still owes; ADR-1 (docs/plan/plan.md) is the decision that
     * retired the CLI subprocess transport these cases were written against.
     */
    private const PENDING =
        'bf-4bd: pdftract --serve exposes no route for %s (ADR-1 in'
        . ' docs/plan/plan.md); coverage parked until upstream adds one.';

    // ---------------------------------------------------------------- search

    /** Was: search() yields one decoded match per NDJSON output line. */
    public function testSearchYieldsOneDecodedMatchPerLine(): void
    {
        $this->markTestSkipped(sprintf(self::PENDING, 'search()'));
    }

    /** Was: search() passes the grep subcommand, pattern, and options as CLI args. */
    public function testSearchPassesGrepSubcommandAndPattern(): void
    {
        $this->markTestSkipped(sprintf(self::PENDING, 'search()'));
    }

    /** Was: search() raises a placeholder message on a failed search with empty stderr. */
    public function testSearchThrowsSearchPlaceholderWhenStderrIsEmpty(): void
    {
        $this->markTestSkipped(sprintf(self::PENDING, 'search()'));
    }

    // ------------------------------------------------ metadata/hash/classify

    /** Was: getMetadata() passes the --metadata-only flag and decodes the result. */
    public function testGetMetadataPassesMetadataOnlyFlag(): void
    {
        $this->markTestSkipped(sprintf(self::PENDING, 'getMetadata()'));
    }

    /** Was: hash() passes the hash subcommand and decodes {hash, fast_hash}. */
    public function testHashPassesHashSubcommand(): void
    {
        $this->markTestSkipped(sprintf(self::PENDING, 'hash()'));
    }

    /** Was: classify() passes the classify subcommand and decodes the verdict. */
    public function testClassifyPassesClassifySubcommand(): void
    {
        $this->markTestSkipped(sprintf(self::PENDING, 'classify()'));
    }

    // ---------------------------------------------------------- verifyReceipt

    /** Was: verifyReceipt() returns true only for literal "true" output. */
    public function testVerifyReceiptReturnsTrueForTrueOutput(): void
    {
        $this->markTestSkipped(sprintf(self::PENDING, 'verifyReceipt()'));
    }

    /** Was: verifyReceipt() returns false for any other output, including empty. */
    public function testVerifyReceiptReturnsFalseForAnyOtherOutput(): void
    {
        $this->markTestSkipped(sprintf(self::PENDING, 'verifyReceipt()'));
    }

    /** Was: verifyReceipt() raises a placeholder message on failure with empty stderr. */
    public function testVerifyReceiptThrowsPlaceholderWhenStderrIsEmpty(): void
    {
        $this->markTestSkipped(sprintf(self::PENDING, 'verifyReceipt()'));
    }

    // -------------------------------------------------------- extractMarkdown

    /** Was: extractMarkdown() returns stdout verbatim. */
    public function testExtractMarkdownReturnsStdoutVerbatim(): void
    {
        $this->markTestSkipped(sprintf(self::PENDING, 'extractMarkdown()'));
    }

    /** Was: extractMarkdown() passes the --md flag first. */
    public function testExtractMarkdownPassesMdFlagFirst(): void
    {
        $this->markTestSkipped(sprintf(self::PENDING, 'extractMarkdown()'));
    }

    /** Was: extractMarkdown() surfaces stderr and the exit code on failure. */
    public function testExtractMarkdownThrowsStderrOnNonZeroExit(): void
    {
        $this->markTestSkipped(sprintf(self::PENDING, 'extractMarkdown()'));
    }

    // --------------------------------------------- logging (six-method cases)

    /** Was: search failures log with search-specific wording. */
    public function testSearchLogsUseSearchWording(): void
    {
        $this->markTestSkipped(sprintf(self::PENDING, 'search()'));
    }

    /** Was: verify-receipt failures log with verify-receipt-specific wording. */
    public function testVerifyReceiptLogsUseVerifyReceiptWording(): void
    {
        $this->markTestSkipped(sprintf(self::PENDING, 'verifyReceipt()'));
    }

    /** Was: markdown extraction failures log with the plain command wording. */
    public function testExtractMarkdownLogsUsePlainCommandWording(): void
    {
        $this->markTestSkipped(sprintf(self::PENDING, 'extractMarkdown()'));
    }
}

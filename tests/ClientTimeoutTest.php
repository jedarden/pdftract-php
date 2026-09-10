<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Client timeout cases parked because the serve API has no route for them.
 *
 * Partition rationale (2026-09-10): this file used to prove that the
 * subprocess transport bounded every pdftract child it spawned — wall-clock
 * deadlines, idle bounds on streams, child termination, and the timeout
 * configuration contract. ADR-1 (docs/plan/plan.md) retired that transport in
 * favour of `pdftract --serve`, and PSR-4 now resolves
 * Jedarden\Pdftract\Client to the canonical HTTP client, so every subprocess
 * case was fataling against the wrong class. Per bf-4gq the legacy suite was
 * partitioned, not deleted:
 *
 * - This file keeps the cases that drove the two methods the serve API
 *   cannot reach — getMetadata and verifyReceipt — as explicit pending
 *   cases, annotated with bf-4bd and ADR-1. Their timeout contracts (the
 *   shorter quick timeout for cheap calls, per-call overrides) were
 *   subprocess-shaped; equivalent coverage for the HTTP client's own
 *   bounding belongs to that client's suite.
 * - The remaining subprocess-timeout cases moved to the superseded partition
 *   under tests/Retired/ (group `retired-cli-subprocess`, excluded from the
 *   default suite in phpunit.xml), retired with the CLI transport — joined
 *   there by tests/verify_psr3_logger.php, which drove the same transport's
 *   logging. They must never be run against the HTTP client.
 *
 * Neither this file nor tests/ClientTest.php references the retired transport
 * or anything under src/Pdftract.
 */
#[Group('pending-no-serve-route')]
class ClientTimeoutTest extends TestCase
{
    /**
     * Why an individual case is parked: bf-4bd tracks the serve-API routes
     * upstream still owes; ADR-1 (docs/plan/plan.md) is the decision that
     * retired the CLI subprocess transport these cases were written against.
     */
    private const PENDING =
        'bf-4bd: pdftract --serve exposes no route for %s (ADR-1 in'
        . ' docs/plan/plan.md); coverage parked until upstream adds one.';

    /** Was: getMetadata() ran under the shorter quick timeout, not the overall default. */
    public function testMetadataUsesTheShorterQuickTimeout(): void
    {
        $this->markTestSkipped(sprintf(self::PENDING, 'getMetadata()'));
    }

    /** Was: verifyReceipt() accepted a per-call timeout override. */
    public function testVerifyReceiptAcceptsAPerCallTimeout(): void
    {
        $this->markTestSkipped(sprintf(self::PENDING, 'verifyReceipt()'));
    }
}

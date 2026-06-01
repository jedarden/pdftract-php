<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests;

use Jedarden\Pdftract\Client;
use Jedarden\Pdftract\Source;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Conformance Test Suite for PHP SDK
 *
 * Runs the shared pdftract conformance suite, verifying that the PHP SDK
 * correctly implements all 9 contract methods across various scenarios.
 *
 * Test cases are loaded from tests/sdk-conformance/cases.json in the main repo.
 */
class ConformanceTest extends TestCase
{
    private const FIXTURES_PATH = __DIR__ . '/../../../../tests/sdk-conformance/fixtures/';
    private const CASES_PATH = __DIR__ . '/../../../../tests/sdk-conformance/cases.json';

    private Client $client;
    private array $cases;
    private array $logEntries = [];

    protected function setUp(): void
    {
        // Load conformance cases
        $casesJson = file_get_contents(self::CASES_PATH);
        if ($casesJson === false) {
            $this->fail('Failed to load conformance cases from ' . self::CASES_PATH);
        }
        $this->cases = json_decode($casesJson, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->fail('Failed to parse conformance cases JSON: ' . json_last_error_msg());
        }

        // Create client with a test logger
        $this->client = new Client('pdftract', $this->createTestLogger());
    }

    /**
     * @dataProvider conformanceProvider
     */
    public function testConformance(array $case): void
    {
        $this->runTestCase($case);
    }

    /**
     * Provides all conformance test cases
     */
    public function conformanceProvider(): array
    {
        $casesJson = file_get_contents(self::CASES_PATH);
        if ($casesJson === false) {
            return [];
        }
        $cases = json_decode($casesJson, true);
        if (!isset($cases['cases']) || !is_array($cases['cases'])) {
            return [];
        }

        $result = [];
        foreach ($cases['cases'] as $case) {
            // Skip cases with skip_reason
            if (isset($case['skip_reason'])) {
                continue;
            }
            $result[$case['id']] = [$case];
        }
        return $result;
    }

    private function runTestCase(array $case): void
    {
        $fixturePath = $this->resolveFixturePath($case['fixture']);
        $method = $case['method'];
        $options = $case['options'] ?? [];
        $expected = $case['expected'] ?? [];

        // Clear log entries for this test
        $this->logEntries = [];

        try {
            switch ($method) {
                case 'extract':
                    $result = $this->client->extract($fixturePath, $this->convertOptions($options));
                    $this->assertExtractResult($result, $expected);
                    break;

                case 'extract_text':
                    $result = $this->client->extractText($fixturePath, $this->convertOptions($options));
                    $this->assertTextResult($result, $expected);
                    break;

                case 'extract_markdown':
                    $result = $this->client->extractMarkdown($fixturePath, $this->convertOptions($options));
                    $this->assertTextResult($result, $expected);
                    break;

                case 'extract_stream':
                    $generator = $this->client->extractStream($fixturePath, $this->convertOptions($options));
                    $results = iterator_to_array($generator);
                    $this->assertStreamResult($results, $expected);
                    break;

                case 'search':
                    $pattern = $options['pattern'] ?? '';
                    $searchOptions = $this->convertOptions($options);
                    unset($searchOptions['pattern']);
                    $generator = $this->client->search($fixturePath, $pattern, $searchOptions);
                    $results = iterator_to_array($generator);
                    $this->assertSearchResult($results, $expected);
                    break;

                case 'get_metadata':
                    $result = $this->client->getMetadata($fixturePath, $this->convertOptions($options));
                    $this->assertMetadataResult($result, $expected);
                    break;

                case 'hash':
                    $result = $this->client->hash($fixturePath, $this->convertOptions($options));
                    $this->assertHashResult($result, $expected);
                    break;

                case 'classify':
                    $result = $this->client->classify($fixturePath, $this->convertOptions($options));
                    $this->assertClassifyResult($result, $expected);
                    break;

                case 'verify_receipt':
                    $receiptPath = $options['receipt'] ?? '';
                    $receiptContent = $this->loadReceipt($receiptPath);
                    $result = $this->client->verifyReceipt($fixturePath, $receiptContent);
                    $this->assertVerifyReceiptResult($result, $expected);
                    break;

                default:
                    $this->fail("Unknown method: {$method}");
            }
        } catch (\Exception $e) {
            $this->fail("Exception running test case {$case['id']}: " . $e->getMessage());
        }
    }

    private function resolveFixturePath(string $fixture): string
    {
        // Handle remote URLs
        if (str_starts_with($fixture, 'http://') || str_starts_with($fixture, 'https://')) {
            return $fixture;
        }

        // Local fixture
        $path = self::FIXTURES_PATH . $fixture;
        if (!file_exists($path)) {
            $this->fail("Fixture not found: {$path}");
        }
        return $path;
    }

    private function convertOptions(array $options): array
    {
        $result = [];
        foreach ($options as $key => $value) {
            // Convert snake_case to camelCase
            $camelKey = $this->toCamelCase($key);
            $result[$camelKey] = $value;
        }
        return $result;
    }

    private function toCamelCase(string $snake): string
    {
        return lcfirst(str_replace('_', '', ucwords($snake, '_')));
    }

    private function loadReceipt(string $receiptPath): string
    {
        $fullPath = self::FIXTURES_PATH . $receiptPath;
        if (!file_exists($fullPath)) {
            $this->fail("Receipt not found: {$fullPath}");
        }
        $content = file_get_contents($fullPath);
        if ($content === false) {
            $this->fail("Failed to read receipt: {$fullPath}");
        }
        return $content;
    }

    private function assertExtractResult(array $result, array $expected): void
    {
        $this->assertArrayHasKey('schema_version', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertArrayHasKey('pages', $result);

        foreach ($expected as $key => $value) {
            $actual = $this->getNestedValue($result, $key);
            $this->assertExpectedValue($actual, $value, $key);
        }
    }

    private function assertTextResult(string $result, array $expected): void
    {
        $this->assertIsString($result);

        if (isset($expected['min_length'])) {
            $this->assertGreaterThanOrEqual($expected['min_length'], strlen($result));
        }

        if (isset($expected['contains']) && is_array($expected['contains'])) {
            foreach ($expected['contains'] as $substring) {
                $this->assertStringContainsString($substring, $result);
            }
        }
    }

    private function assertStreamResult(array $results, array $expected): void
    {
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);

        if (isset($expected['frame_count'])) {
            $frameCount = $expected['frame_count'];
            if (isset($frameCount['min'])) {
                $this->assertGreaterThanOrEqual($frameCount['min'], count($results));
            }
            if (isset($frameCount['max'])) {
                $this->assertLessThanOrEqual($frameCount['max'], count($results));
            }
        }

        if (isset($expected['first_frame_type'])) {
            $this->assertEquals($expected['first_frame_type'], $results[0]['kind'] ?? null);
        }

        if (isset($expected['last_frame_type'])) {
            $last = end($results);
            $this->assertEquals($expected['last_frame_type'], $last['kind'] ?? null);
        }
    }

    private function assertSearchResult(array $results, array $expected): void
    {
        $this->assertIsArray($results);

        if (isset($expected['min_matches'])) {
            $this->assertGreaterThanOrEqual($expected['min_matches'], count($results));
        }

        if (isset($expected['match_count'])) {
            $this->assertEquals($expected['match_count'], count($results));
        }

        if (isset($expected['first_match_page'])) {
            $this->assertEquals($expected['first_match_page'], $results[0]['page_index'] ?? null);
        }

        if (isset($expected['first_match_text'])) {
            $this->assertStringContainsString($expected['first_match_text'], $results[0]['text'] ?? '');
        }
    }

    private function assertMetadataResult(array $result, array $expected): void
    {
        $this->assertIsArray($result);
        $this->assertArrayHasKey('page_count', $result);

        foreach ($expected as $key => $value) {
            $actual = $this->getNestedValue($result, $key);
            $this->assertExpectedValue($actual, $value, $key);
        }
    }

    private function assertHashResult(array $result, array $expected): void
    {
        $this->assertIsArray($result);
        $this->assertArrayHasKey('hash', $result);
        $this->assertArrayHasKey('fast_hash', $result);

        if (isset($expected['hash.length'])) {
            $this->assertEquals($expected['hash.length'], strlen($result['hash']));
        }

        if (isset($expected['fast_hash.length'])) {
            $this->assertEquals($expected['fast_hash.length'], strlen($result['fast_hash']));
        }

        if (isset($expected['hash_different_from_fast_hash'])) {
            $this->assertNotEquals($result['hash'], $result['fast_hash']);
        }
    }

    private function assertClassifyResult(array $result, array $expected): void
    {
        $this->assertIsArray($result);
        $this->assertArrayHasKey('category', $result);
        $this->assertArrayHasKey('confidence', $result);

        if (isset($expected['category'])) {
            $this->assertEquals($expected['category'], $result['category']);
        }

        if (isset($expected['confidence'])) {
            $confidence = $expected['confidence'];
            if (isset($confidence['min'])) {
                $this->assertGreaterThanOrEqual($confidence['min'], $result['confidence']);
            }
        }
    }

    private function assertVerifyReceiptResult(bool $result, array $expected): void
    {
        $this->assertIsBool($result);
        if (isset($expected['valid'])) {
            $this->assertEquals($expected['valid'], $result);
        }
    }

    private function getNestedValue(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            // Handle array notation like pages[0]
            if (preg_match('/^(.+)\[(\d+)\]$/', $key, $matches)) {
                $key = $matches[1];
                $index = (int)$matches[2];
                if (!isset($value[$key])) {
                    return null;
                }
                $value = $value[$key];
                if (!isset($value[$index])) {
                    return null;
                }
                $value = $value[$index];
            } else {
                if (!isset($value[$key])) {
                    return null;
                }
                $value = $value[$key];
            }
        }

        return $value;
    }

    private function assertExpectedValue($actual, $expected, string $path): void
    {
        if (is_array($expected)) {
            if (isset($expected['min'])) {
                $this->assertGreaterThanOrEqual($expected['min'], $actual, "Failed for path: {$path}");
            }
            if (isset($expected['max'])) {
                $this->assertLessThanOrEqual($expected['max'], $actual, "Failed for path: {$path}");
            }
        } else {
            $this->assertEquals($expected, $actual, "Failed for path: {$path}");
        }
    }

    private function createTestLogger(): LoggerInterface
    {
        return new class($this) implements LoggerInterface {
            private ConformanceTest $test;
            private array $logLevels = [
                LogLevel::DEBUG,
                LogLevel::INFO,
                LogLevel::NOTICE,
                LogLevel::WARNING,
                LogLevel::ERROR,
                LogLevel::CRITICAL,
                LogLevel::ALERT,
                LogLevel::EMERGENCY,
            ];

            public function __construct(ConformanceTest $test)
            {
                $this->test = $test;
            }

            public function emergency(\Stringable|string $message, array $context = []): void
            {
                $this->log(LogLevel::EMERGENCY, $message, $context);
            }

            public function alert(\Stringable|string $message, array $context = []): void
            {
                $this->log(LogLevel::ALERT, $message, $context);
            }

            public function critical(\Stringable|string $message, array $context = []): void
            {
                $this->log(LogLevel::CRITICAL, $message, $context);
            }

            public function error(\Stringable|string $message, array $context = []): void
            {
                $this->log(LogLevel::ERROR, $message, $context);
            }

            public function warning(\Stringable|string $message, array $context = []): void
            {
                $this->log(LogLevel::WARNING, $message, $context);
            }

            public function notice(\Stringable|string $message, array $context = []): void
            {
                $this->log(LogLevel::NOTICE, $message, $context);
            }

            public function info(\Stringable|string $message, array $context = []): void
            {
                $this->log(LogLevel::INFO, $message, $context);
            }

            public function debug(\Stringable|string $message, array $context = []): void
            {
                $this->log(LogLevel::DEBUG, $message, $context);
            }

            private function log(string $level, \Stringable|string $message, array $context = []): void
            {
                $this->test->logEntries[] = [
                    'level' => $level,
                    'message' => (string)$message,
                    'context' => $context,
                ];
            }
        };
    }

    public function testLoggerReceivesDebugLogs(): void
    {
        $this->logEntries = [];
        $this->client->extract($this->resolveFixturePath('scientific_paper/01.pdf'));

        $debugLogs = array_filter($this->logEntries, fn($e) => $e['level'] === LogLevel::DEBUG);
        $this->assertNotEmpty($debugLogs, 'Client should log debug messages');
    }

    public function testAllNineMethodsExist(): void
    {
        $methods = [
            'extract',
            'extractText',
            'extractMarkdown',
            'extractStream',
            'search',
            'getMetadata',
            'hash',
            'classify',
            'verifyReceipt',
        ];

        foreach ($methods as $method) {
            $this->assertTrue(method_exists($this->client, $method), "Missing method: {$method}");
        }
    }
}

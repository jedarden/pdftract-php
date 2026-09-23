<?php

declare(strict_types=1);

namespace Jedarden\Pdftract\Tests;

use Jedarden\Pdftract\AuthenticationException;
use Jedarden\Pdftract\ConfigurationException;
use Jedarden\Pdftract\ConnectionException;
use Jedarden\Pdftract\EncodingException;
use Jedarden\Pdftract\IOException;
use Jedarden\Pdftract\NotFoundException;
use Jedarden\Pdftract\ParseException;
use Jedarden\Pdftract\PdftractException;
use Jedarden\Pdftract\RateLimitException;
use Jedarden\Pdftract\TimeoutException;
use Jedarden\Pdftract\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The exception hierarchy resolves from the live src/ root
 *
 * The serve-API exception types existed only in the autoloader-shadowed
 * dead tree (src/Pdftract/Codegen/, whose declared Jedarden\Pdftract\Codegen
 * namespace no PSR-4 root reaches) until bf-4gq ported them flat, matching
 * the placement of ConfigurationException/ConnectionException/
 * TimeoutException. Each class's file location is pinned here so a copy
 * that only loads from the dead tree, or a re-nesting into src/Exception/,
 * fails this suite instead of failing a caller at autoload time.
 */
final class ExceptionHierarchyTest extends TestCase
{
    #[DataProvider('provideExceptionClasses')]
    public function test_each_exception_resolves_from_the_live_src_root_and_extends_the_base(string $class): void
    {
        self::assertTrue(class_exists($class), "{$class} must autoload");

        $reflection = new \ReflectionClass($class);

        self::assertTrue(
            $reflection->isSubclassOf(PdftractException::class),
            "{$class} must extend PdftractException, the common base",
        );

        $expectedFile = dirname(__DIR__) . '/src/' . $reflection->getShortName() . '.php';

        self::assertSame(
            $expectedFile,
            $reflection->getFileName(),
            "{$class} must be defined flat in the live src/ root",
        );
    }

    public static function provideExceptionClasses(): array
    {
        return [
            'authentication' => [AuthenticationException::class],
            'configuration' => [ConfigurationException::class],
            'connection' => [ConnectionException::class],
            'encoding' => [EncodingException::class],
            'io' => [IOException::class],
            'not found' => [NotFoundException::class],
            'parse' => [ParseException::class],
            'rate limit' => [RateLimitException::class],
            'timeout' => [TimeoutException::class],
            'validation' => [ValidationException::class],
        ];
    }

    public function test_the_base_class_is_not_one_of_its_own_subclasses(): void
    {
        $reflection = new \ReflectionClass(PdftractException::class);

        self::assertSame(
            dirname(__DIR__) . '/src/PdftractException.php',
            $reflection->getFileName(),
            'the base must live flat in the live src/ root',
        );
        self::assertSame(\Exception::class, get_parent_class(PdftractException::class), 'the base extends SPL Exception');
    }
}

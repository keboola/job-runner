<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Helper\ExceptionConverter;
use Exception;
use Generator;
use Keboola\ConfigurationVariablesResolver\Exception\UserException as OutsideUserException;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\JobQueueInternalClient\Result\JobResult;
use Keboola\ObjectEncryptor\Exception\UserException as EncryptionUserException;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;
use Throwable;

class ExceptionConverterTest extends TestCase
{
    /**
     * @dataProvider provideExceptions
     */
    public function testExceptionConversion(
        Throwable $exception,
        string $expectedErrorType,
        string $expectedMessage,
        string $expectedLog,
        string $method,
    ): void {
        $logger = new TestLogger();
        $result = ExceptionConverter::convertExceptionToResult($logger, $exception, '123', []);
        self::assertEquals($expectedMessage, $result->getMessage());
        self::assertEquals($expectedErrorType, $result->getErrorType());
        self::assertStringStartsWith('exception-', (string) $result->getExceptionId());
        self::assertNull($result->getConfigVersion());
        self::assertSame([], $result->getImages());
        self::assertTrue($logger->$method($expectedLog));
    }

    public function provideExceptions(): Generator
    {
        yield 'encryption exception' => [
            'exception' => new EncryptionUserException('some error'),
            'expectedErrorType' => JobResult::ERROR_TYPE_USER,
            'expectedMessage' => 'some error',
            'expectedLog' => 'Job "123" ended with encryption error: "some error"',
            'method' => 'hasErrorThatContains',
        ];
        yield 'user exception' => [
            'exception' => new UserException('some error'),
            'expectedErrorType' => JobResult::ERROR_TYPE_USER,
            'expectedMessage' => 'some error',
            'expectedLog' => 'Job "123" ended with user error: "some error"',
            'method' => 'hasErrorThatContains',
        ];
        yield 'user exception interface' => [
            'exception' => new OutsideUserException('some error'),
            'expectedErrorType' => JobResult::ERROR_TYPE_USER,
            'expectedMessage' => 'some error',
            'expectedLog' => 'Job "123" ended with user error: "some error"',
            'method' => 'hasErrorThatContains',
        ];
        yield 'application exception' => [
            'exception' => new ApplicationException('some error'),
            'expectedErrorType' => JobResult::ERROR_TYPE_APPLICATION,
            'expectedMessage' => 'Internal Server Error occurred.',
            'expectedLog' => 'Job "123" ended with application error: "Internal Server Error occurred."',
            'method' => 'hasCriticalThatContains',
        ];
        yield 'unknown exception' => [
            'exception' => new Exception('some error'),
            'expectedErrorType' => JobResult::ERROR_TYPE_APPLICATION,
            'expectedMessage' => 'Internal Server Error occurred.',
            'expectedLog' => 'Job "123" ended with application error: "Internal Server Error occurred."',
            'method' => 'hasCriticalThatContains',
        ];
    }

    public function testExceptionConversionOutputs(): void
    {
        $logger = new TestLogger();
        $output = new Output();
        $output->setImages(['a' => 'b']);
        $output->setConfigVersion('123');

        $result = ExceptionConverter::convertExceptionToResult(
            $logger,
            new UserException('some error'),
            '123',
            [
                $output,
            ],
        );
        self::assertEquals('some error', $result->getMessage());
        self::assertSame('123', $result->getConfigVersion());
        self::assertSame(
            [['a' => 'b']],
            $result->getImages(),
        );
    }
}

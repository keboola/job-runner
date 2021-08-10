<?php

namespace App\Tests;

use App\ExceptionConverterHelper;
use Exception;
use Generator;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\JobQueueInternalClient\JobFactory\JobResult;
use Keboola\ObjectEncryptor\Exception\UserException as EncryptionUserException;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;
use Throwable;

class ExceptionConverterHelperTest extends TestCase
{
    /**
     * @dataProvider provideExceptions
     */
    public function testExceptionConversion(
        Throwable $exception,
        string $expectedErrorType,
        string $expectedMessage,
        string $expectedLog
    ): void {
        $logger = new TestLogger();
        $result = ExceptionConverterHelper::convertExceptionToResult($logger, $exception, '123');
        self::assertEquals($expectedMessage, $result->getMessage());
        self::assertEquals($expectedErrorType, $result->getErrorType());
        self::assertStringStartsWith('exception-', (string) $result->getExceptionId());
        self::assertEquals(null, $result->getConfigVersion());
        self::assertEquals([], $result->getImages());
        self::assertTrue($logger->hasErrorThatContains($expectedLog));
    }

    public function provideExceptions(): Generator
    {
        yield 'encryption exception' => [
            'exception' => new EncryptionUserException('some error'),
            'expectedErrorType' => JobResult::ERROR_TYPE_APPLICATION,
            'expectedMessage' => 'Internal Server Error occurred.',
            'expectedLog' => 'Job "123" ended with encryption error: "Internal Server Error occurred."',
        ];
        yield 'user exception' => [
            'exception' => new UserException('some error'),
            'expectedErrorType' => JobResult::ERROR_TYPE_USER,
            'expectedMessage' => 'some error',
            'expectedLog' => 'Job "123" ended with user error: "some error"',
        ];
        yield 'application exception' => [
            'exception' => new ApplicationException('some error'),
            'expectedErrorType' => JobResult::ERROR_TYPE_APPLICATION,
            'expectedMessage' => 'Internal Server Error occurred.',
            'expectedLog' => 'Job "123" ended with application error: "Internal Server Error occurred."',
        ];
        yield 'unknown exception' => [
            'exception' => new Exception('some error'),
            'expectedErrorType' => JobResult::ERROR_TYPE_APPLICATION,
            'expectedMessage' => 'Internal Server Error occurred.',
            'expectedLog' => 'Job "123" ended with application error: "Internal Server Error occurred."',
        ];
    }
}

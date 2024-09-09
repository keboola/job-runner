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
use Keboola\InputMapping\Table\Result;
use Keboola\InputMapping\Table\Result\TableInfo;
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

        $variables = $result->getVariables();
        self::assertNotNull($variables);
        self::assertCount(0, $variables);

        $inputTables = $result->getInputTables();
        self::assertNotNull($inputTables);
        self::assertCount(0, $inputTables);
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
        $output1 = new Output();
        $output1->setImages(['a' => 'b']);
        $output1->setConfigVersion('123');
        $output1->setInputVariableValues(['foo' => 'bar']);

        $inputTableResult1 = new Result();
        $inputTableResult1->addTable(new TableInfo([
            'id' => 'in.c-main.my-first-table',
            'displayName' => 'My first table',
            'name' => 'my-first-table',
            'lastImportDate' => '2021-02-12T10:36:15+0100',
            'lastChangeDate' => '2021-12-12T10:36:15+0100',
            'columns' => [
                'first',
                'second',
            ],
        ]));

        $output1->setInputTableResult($inputTableResult1);

        $output2 = new Output();
        $output2->setImages(['c' => 'd']);
        $output2->setInputVariableValues(['vault.foo' => 'vault bar']);

        $inputTableResult2 = new Result();
        $inputTableResult2->addTable(new TableInfo([
            'id' => 'in.c-main.my-second-table',
            'displayName' => 'My second table',
            'name' => 'my-second-table',
            'lastImportDate' => '2024-01-11T10:36:15+0100',
            'lastChangeDate' => '2024-01-11T10:36:15+0100',
            'columns' => [
                'dummyColumn',
            ],
        ]));

        $output2->setInputTableResult($inputTableResult2);

        $result = ExceptionConverter::convertExceptionToResult(
            $logger,
            new UserException('some error'),
            '123',
            [
                $output1,
                $output2,
            ],
        );
        self::assertEquals('some error', $result->getMessage());
        self::assertSame('123', $result->getConfigVersion());
        self::assertSame(
            [
                ['a' => 'b'],
                ['c' => 'd'],
            ],
            $result->getImages(),
        );
        $variables = $result->getVariables();
        self::assertNotNull($variables);
        self::assertSame(
            [
                [
                    'name'=> 'foo',
                    'value'=> 'bar',
                ],
                [
                    'name'=> 'vault.foo',
                    'value'=> 'vault bar',
                ],
            ],
            $variables->jsonSerialize(),
        );

        $inputTables = $result->getInputTables();
        self::assertNotNull($inputTables);
        self::assertSame(
            [
                [
                    'id' => 'in.c-main.my-first-table',
                    'name' => 'my-first-table',
                    'displayName' => 'My first table',
                    'columns' => [
                        [
                            'name' => 'first',
                        ],
                        [
                            'name' => 'second',
                        ],
                    ],
                ],
                [
                    'id' => 'in.c-main.my-second-table',
                    'name' => 'my-second-table',
                    'displayName' => 'My second table',
                    'columns' => [
                        [
                            'name' => 'dummyColumn',
                        ],
                    ],
                ],
            ],
            $inputTables->jsonSerialize(),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests;

use App\StorageApiHandler;
use Generator;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Event;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class StorageApiHandlerTest extends TestCase
{
    public function testSetAndGetVerbosity(): void
    {
        $handler = new StorageApiHandler(
            'StorageApiHandlerTest',
            $this->createMock(Client::class),
        );

        // default verbosity
        self::assertSame(
            [
                100 => 'none',
                200 => 'normal',
                250 => 'normal',
                300 => 'normal',
                400 => 'normal',
                500 => 'camouflage',
                550 => 'camouflage',
                600 => 'camouflage',
            ],
            $handler->getVerbosity(),
        );

        // set own verbosity (append to existing)
        $handler->setVerbosity([
            999 => StorageAPIHandler::VERBOSITY_VERBOSE,
        ]);

        self::assertSame(
            [
                100 => 'none',
                200 => 'normal',
                250 => 'normal',
                300 => 'normal',
                400 => 'normal',
                500 => 'camouflage',
                550 => 'camouflage',
                600 => 'camouflage',
                999 => 'verbose',
            ],
            $handler->getVerbosity(),
        );
    }

    public function handleSkipsDispatchToSapiDataProvider(): Generator
    {
        yield 'empty message' => [
            [
                'message' => '',
                'level' => Logger::INFO,
            ],
        ];

        yield 'level with non verbosity' => [
            [
                'message' => 'Debug World',
                'level' => Logger::DEBUG,
            ],
        ];
    }

    /**
     * @dataProvider handleSkipsDispatchToSapiDataProvider
     */
    public function testHandleSkipsDispatchToSapi(array $record): void
    {
        $storageApiClientMock = $this->createMock(Client::class);
        $storageApiClientMock
            ->expects(self::never())
            ->method(self::anything())
        ;

        $handler = new StorageApiHandler(
            'StorageApiHandlerTest',
            $storageApiClientMock,
        );

        self::assertFalse($handler->handle($record));
    }

    public function handleDataProvider(): Generator
    {
        yield 'minimal record' => [
            [
                'message' => 'Hello World',
                'level' => Logger::INFO,
            ],
            'expectedMessage' => 'Hello World',
            'expectedComponent' => 'StorageApiHandlerTest', // default - use app name
            'expectedType' => 'info',
            'expectedResults' => [],
            'expectedDescription' => null,
        ];
        yield 'info record' => [
            [
                'message' => 'Hello World Info',
                'level' => Logger::INFO,
                'component' => 'MyComponent',
                'context' => ['foo' => 'bar'],
            ],
            'expectedMessage' => 'Hello World Info',
            'expectedComponent' => 'MyComponent',
            'expectedType' => 'info',
            'expectedResults' => [], // censored due non-verbose verbosity
            'expectedDescription' => null,
        ];
        yield 'notice record' => [
            [
                'message' => 'Hello World Notice',
                'level' => Logger::NOTICE,
                'component' => 'MyComponent',
                'context' => ['foo' => 'bar'],
            ],
            'expectedMessage' => 'Hello World Notice',
            'expectedComponent' => 'MyComponent',
            'expectedType' => 'warn',
            'expectedResults' => [], // censored due non-verbose verbosity
            'expectedDescription' => null,
        ];
        yield 'warning record' => [
            [
                'message' => 'Hello World Warning',
                'level' => Logger::WARNING,
                'component' => 'MyComponent',
                'context' => ['foo' => 'bar'],
            ],
            'expectedMessage' => 'Hello World Warning',
            'expectedComponent' => 'MyComponent',
            'expectedType' => 'warn',
            'expectedResults' => [], // censored due non-verbose verbosity
            'expectedDescription' => null,
        ];
        yield 'critical record' => [
            [
                'message' => 'Hello World Critical',
                'level' => Logger::CRITICAL,
                'component' => 'MyComponent',
                'context' => ['foo' => 'bar'],
            ],
            'expectedMessage' => 'Application error', // censored
            'expectedComponent' => 'MyComponent',
            'expectedType' => 'error',
            'expectedResults' => [], // censored due non-verbose verbosity
            'expectedDescription' => 'Please contact Keboola Support for help.',
        ];
        yield 'emergency record' => [
            [
                'message' => 'Hello World Emergency',
                'level' => Logger::EMERGENCY,
                'component' => 'MyComponent',
                'context' => ['foo' => 'bar'],
            ],
            'expectedMessage' => 'Application error', // censored
            'expectedComponent' => 'MyComponent',
            'expectedType' => 'error',
            'expectedResults' => [], // censored due non-verbose verbosity
            'expectedDescription' => 'Please contact Keboola Support for help.',
        ];
        yield 'alert record' => [
            [
                'message' => 'Hello World Alert',
                'level' => Logger::ALERT,
                'component' => 'MyComponent',
                'context' => ['foo' => 'bar'],
            ],
            'expectedMessage' => 'Application error', // censored
            'expectedComponent' => 'MyComponent',
            'expectedType' => 'error',
            'expectedResults' => [], // censored due non-verbose verbosity
            'expectedDescription' => 'Please contact Keboola Support for help.',
        ];
        yield 'error record' => [
            [
                'message' => 'Hello World Error',
                'level' => Logger::ERROR,
                'component' => 'MyComponent',
                'context' => ['foo' => 'bar'],
            ],
            'expectedMessage' => 'Hello World Error',
            'expectedComponent' => 'MyComponent',
            'expectedType' => 'error',
            'expectedResults' => [], // censored due non-verbose verbosity
            'expectedDescription' => null,
        ];
        yield 'record with verbose' => [
            [
                'message' => 'Hello World Verbose',
                'level' => 999,
                'component' => 'MyComponent',
                'context' => ['foo' => 'bar'],
            ],
            'expectedMessage' => 'Hello World Verbose',
            'expectedComponent' => 'MyComponent',
            'expectedType' => 'info',
            'expectedResults' => ['foo' => 'bar'],
            'expectedDescription' => null,
        ];
    }

    /**
     * @dataProvider handleDataProvider
     */
    public function testHandle(
        array $record,
        string $expectedMessage,
        string $expectedComponent,
        string $expectedType,
        array $expectedResults,
        ?string $expectedDescription,
    ): void {
        $storageApiClientMock = $this->createMock(Client::class);
        $storageApiClientMock
            ->expects(self::once())
            ->method('createEvent')
            ->with(self::callback(
                function (Event $event) use (
                    $expectedMessage,
                    $expectedComponent,
                    $expectedType,
                    $expectedResults,
                    $expectedDescription,
                ): bool {
                    self::assertSame($expectedMessage, $event->getMessage());
                    self::assertSame($expectedComponent, $event->getComponent());
                    self::assertSame($expectedType, $event->getType());
                    self::assertSame($expectedResults, $event->getResults());
                    self::assertSame($expectedDescription, $event->getDescription());
                    self::assertSame('456', $event->getRunId());
                    return true;
                },
            ))
        ;

        $storageApiClientMock->expects(self::once())
            ->method('getRunId')
            ->willReturn('456');

        $handler = new StorageApiHandler(
            'StorageApiHandlerTest',
            $storageApiClientMock,
        );

        $handler->setVerbosity([999 => StorageApiHandler::VERBOSITY_VERBOSE]);

        self::assertFalse($handler->handle($record));
    }

    public function testHandleTruncatesLargeMessages(): void
    {
        $storageApiClientMock = $this->createMock(Client::class);
        $storageApiClientMock
            ->expects(self::once())
            ->method('createEvent')
            ->with(self::callback(
                function (Event $event): bool {
                    self::assertSame(3999, mb_strlen($event->getMessage()));
                    self::assertStringContainsString(' ... ', $event->getMessage());
                    return true;
                },
            ))
        ;

        $handler = new StorageApiHandler(
            'StorageApiHandlerTest',
            $storageApiClientMock,
        );

        $text = 'Lorem ipsum dolor sit amet, consectetur adipisici elit.';
        $longText = str_repeat($text, 100); // 5500 chars

        self::assertFalse($handler->handle([
            'message' => $longText,
            'level' => Logger::INFO,
        ]));
    }
}

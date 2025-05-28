<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Helper\OutputResultConverter;
use Keboola\Artifacts\Result;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\InputMapping\Table\Result as InputResult;
use Keboola\InputMapping\Table\Result\TableInfo;
use Keboola\JobQueue\JobConfiguration\Mapping\StagingWorkspace\StagingWorkspaceFacade;
use Keboola\JobQueueInternalClient\JobFactory\Runtime\Backend;
use Keboola\OutputMapping\DeferredTasks\LoadTableQueue;
use Keboola\OutputMapping\Table\Result as OutputResult;
use PHPUnit\Framework\TestCase;

class OutputResultConverterTest extends TestCase
{
    public function testEmptyResult(): void
    {
        $jobResult = OutputResultConverter::convertOutputsToResult([]);
        self::assertSame(
            [
                'message' => 'No configurations executed.',
                'configVersion' => null,
                'images' => [],
                'input' => [
                    'tables' => [],
                ],
                'output' => [
                    'tables' => [],
                ],
            ],
            $jobResult->jsonSerialize(),
        );
    }

    public function testNoMetrics(): void
    {
        $jobMetrics = OutputResultConverter::convertOutputsToMetrics([], new Backend(null, null, null));
        self::assertSame(
            [
                'storage' => [
                    'inputTablesBytesSum' => 0,
                    'outputTablesBytesSum' => 0,
                ],
                'backend' => [
                    'size' => null,
                    'containerSize' => 'small',
                    'context' => null,
                ],
            ],
            $jobMetrics->jsonSerialize(),
        );
    }

    public function testEmptyMetrics(): void
    {
        $output = new Output();
        $inputTableResult = new InputResult();
        $output->setInputTableResult($inputTableResult);
        $jobMetrics = OutputResultConverter::convertOutputsToMetrics([$output], new Backend(null, 'medium', null));
        self::assertSame(
            [
                'storage' => [
                    'inputTablesBytesSum' => 0,
                    'outputTablesBytesSum' => 0,
                ],
                'backend' => [
                    'size' => null,
                    'containerSize' => 'medium',
                    'context' => null,
                ],
            ],
            $jobMetrics->jsonSerialize(),
        );
    }

    public function testFull(): void
    {
        $inputTableResult1 = new InputResult();
        $inputTableResult1->addTable($this->getTableInfo()['first']);
        $inputTableResult1->addTable($this->getTableInfo()['second']);
        $inputTableResult1->setMetrics([
            [
                'id' => 123456,
                'status' => 'success',
                'url' => 'https://connection.keboola.com/v2/storage/jobs/123456',
                'tableId' => 'in.c-main.my-second-table',
                'createdTime' => '2017-02-13T16:41:18+0100',
                'metrics' => [
                    'inCompressed' => false,
                    'inBytes' => 0,
                    'inBytesUncompressed' => 0,
                    'outCompressed' => true,
                    'outBytes' => 500,
                    'outBytesUncompressed' => 0,
                ],
            ],
            [
                'id' => 654321,
                'status' => 'success',
                'url' => 'https://connection.keboola.com/v2/storage/jobs/654321',
                'tableId' => 'in.c-main.my-first-table',
                'createdTime' => '2018-02-13T16:41:18+0100',
                'metrics' => [
                    'inCompressed' => false,
                    'inBytes' => 0,
                    'inBytesUncompressed' => 1000,
                    'outCompressed' => true,
                    'outBytes' => 3000,
                    'outBytesUncompressed' => 300,
                ],
            ],
        ]);
        $outputTableResult1 = new OutputResult();
        $outputTableResult1->addTable($this->getTableInfo()['third']);
        $outputTableResult1->addTable($this->getTableInfo()['fourth']);
        $outputTableResult1->setMetrics(new OutputResult\Metrics([
            [
                'id' => 234567,
                'status' => 'success',
                'url' => 'https://connection.keboola.com/v2/storage/jobs/234567',
                'tableId' => 'in.c-main.my-third-table',
                'operationName' => 'tableImport',
                'createdTime' => '2021-03-12T10:36:15+0100',
                'metrics' => [
                    'inCompressed' => true,
                    'inBytes' => 100,
                    'inBytesUncompressed' => 0,
                    'outCompressed' => false,
                    'outBytes' => 0,
                    'outBytesUncompressed' => 0,
                ],
            ],
            [
                'id' => 765432,
                'status' => 'success',
                'url' => 'https://connection.keboola.com/v2/storage/jobs/765432',
                'tableId' => null,
                'operationName' => 'tableCreate',
                'createdTime' => '2001-03-12T10:36:15+0100',
                'results' => [
                    'id' => 'in.c-main.my-fourth-table',
                ],
                'metrics' => [
                    'inCompressed' => true,
                    'inBytes' => 100,
                    'inBytesUncompressed' => 1000,
                    'outCompressed' => false,
                    'outBytes' => 3000,
                    'outBytesUncompressed' => 300,
                ],
            ],
        ]));
        $loadQueueMock1 = self::createMock(LoadTableQueue::class);
        $loadQueueMock1->method('getTableResult')->willReturn($outputTableResult1);

        $output1 = new Output();
        $output1->setConfigVersion('123');
        $output1->setImages(['a' => 'b']);
        $output1->setOutput('some output');
        $output1->setInputTableResult($inputTableResult1);
        $output1->setOutputTableResult($outputTableResult1);
        $output1->setTableQueue($loadQueueMock1);
        $output1->setArtifactsUploaded([
            new Result(12344),
            new Result(12347, true),
        ]);
        $output1->setArtifactsDownloaded([
            new Result(12345),
            new Result(12346),
        ]);
        $output1->setInputVariableValues([
            'vault.foo' => 'vault bar',
        ]);

        $inputTableResult2 = new InputResult();
        $inputTableResult2->addTable($this->getTableInfo()['fifth']);
        $outputTableResult2 = new OutputResult();
        $outputTableResult2->addTable($this->getTableInfo()['first']);
        $loadQueueMock2 = self::createMock(LoadTableQueue::class);
        $loadQueueMock2->method('getTableResult')->willReturn($outputTableResult2);

        $output2 = new Output();
        $output2->setConfigVersion('123');
        $output2->setImages(['c' => 'd']);
        $output2->setOutput('some other output');
        $output2->setInputTableResult($inputTableResult2);
        $output2->setOutputTableResult($outputTableResult2);
        $output2->setTableQueue($loadQueueMock2);
        $output2->setArtifactsUploaded([
            new Result(23456),
            new Result(23480, true),
        ]);
        $output2->setArtifactsDownloaded([
            new Result(23467),
            new Result(23478),
            new Result(23479),
        ]);
        $output2->setInputVariableValues([
            'foo' => 'bar',
        ]);

        $stagingWorkspace = self::createMock(StagingWorkspaceFacade::class);
        $stagingWorkspace->expects(self::once())
            ->method('getBackendSize')
            ->willReturn('large')
        ;
        $output1->setStagingWorkspace($stagingWorkspace);

        $stagingWorkspace = self::createMock(StagingWorkspaceFacade::class);
        $stagingWorkspace->expects(self::once())
            ->method('getBackendSize')
            ->willReturn(null)
        ;
        $output2->setStagingWorkspace($stagingWorkspace);

        $outputs = [$output1, $output2];
        $jobResult = OutputResultConverter::convertOutputsToResult($outputs);
        $jobMetrics = OutputResultConverter::convertOutputsToMetrics($outputs, new Backend(null, null, 'wlm'));
        self::assertSame(
            [
                'message' => 'Component processing finished.',
                'configVersion' => '123',
                'images' => [
                    ['a' => 'b'],
                    ['c' => 'd'],
                ],
                'input' => [
                    'tables' => [
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
                            'displayName' => 'My Second table',
                            'columns' => [
                                [
                                    'name' => 'third',
                                ],
                                [
                                    'name' => 'fourth',
                                ],
                            ],
                        ],
                        [
                            'id' => 'in.c-main.my-fifth-table',
                            'name' => 'my-fifth-table',
                            'displayName' => 'My Fifth table',
                            'columns' => [
                                [
                                    'name' => 'zero',
                                ],
                                [
                                    'name' => 'one',
                                ],
                            ],
                        ],
                    ],
                ],
                'output' => [
                    'tables' => [
                        [
                            'id' => 'in.c-main.my-third-table',
                            'name' => 'my-third-table',
                            'displayName' => 'My Third table',
                            'columns' => [
                                [
                                    'name' => 'fifth',
                                ],
                                [
                                    'name' => 'sixth',
                                ],
                            ],
                        ],
                        [
                            'id' => 'in.c-main.my-fourth-table',
                            'name' => 'my-fourth-table',
                            'displayName' => 'My Fourth table',
                            'columns' => [
                                [
                                    'name' => 'seventh',
                                ],
                                [
                                    'name' => 'eight',
                                ],
                            ],
                        ],
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
                    ],
                ],
                'artifacts' => [
                    'uploaded' => [
                        ['storageFileId' => '12344'],
                        ['storageFileId' => '12347'],
                        ['storageFileId' => '23456'],
                        ['storageFileId' => '23480'],
                    ],
                    'downloaded' => [
                        ['storageFileId' => '12345'],
                        ['storageFileId' => '12346'],
                        ['storageFileId' => '23467'],
                        ['storageFileId' => '23478'],
                        ['storageFileId' => '23479'],
                    ],
                ],
                'variables' => [
                    [
                        'name' => 'vault.foo',
                        'value' => 'vault bar',
                    ],
                    [
                        'name' => 'foo',
                        'value' => 'bar',
                    ],
                ],
            ],
            $jobResult->jsonSerialize(),
        );
        self::assertSame(
            [
                'storage' => [
                    'inputTablesBytesSum' => 3500,
                    'outputTablesBytesSum' => 200,
                ],
                'backend' => [
                    'size' => 'large',
                    'containerSize' => 'small',
                    'context' => 'wlm',
                ],
            ],
            $jobMetrics->jsonSerialize(),
        );
    }

    public function testEmptyArtifacts(): void
    {
        $output = new Output();
        $output->setConfigVersion('123');
        $output->setImages(['a' => 'b']);
        $output->setOutput('some output');
        $output->setArtifactsUploaded([]);
        $output->setArtifactsDownloaded([]);

        $output2 = new Output();
        $output2->setConfigVersion('124');
        $output2->setImages(['c' => 'd']);
        $output2->setOutput('some output 2');
        $output2->setArtifactsUploaded([]);
        $output2->setArtifactsDownloaded([]);

        $outputs = [$output, $output2];
        $jobResult = OutputResultConverter::convertOutputsToResult($outputs);
        self::assertSame(
            [
                'message' => 'Component processing finished.',
                'configVersion' => '123',
                'images' => [
                    ['a' => 'b'],
                    ['c' => 'd'],
                ],
                'input' => [
                    'tables' => [],
                ],
                'output' => [
                    'tables' => [],
                ],
                'artifacts' => [
                    'uploaded' => [],
                    'downloaded' => [],
                ],
                'variables' => [],
            ],
            $jobResult->jsonSerialize(),
        );
    }

    public function testEmptyVariables(): void
    {
        $output = new Output();
        $output->setConfigVersion('123');
        $output->setImages(['a' => 'b']);
        $output->setOutput('some output');
        $output->setInputVariableValues([]);

        $output2 = new Output();
        $output2->setConfigVersion('124');
        $output2->setImages(['c' => 'd']);
        $output2->setOutput('some output 2');
        $output2->setInputVariableValues([]);

        $outputs = [$output, $output2];
        $jobResult = OutputResultConverter::convertOutputsToResult($outputs);
        self::assertSame(
            [
                'message' => 'Component processing finished.',
                'configVersion' => '123',
                'images' => [
                    ['a' => 'b'],
                    ['c' => 'd'],
                ],
                'input' => [
                    'tables' => [],
                ],
                'output' => [
                    'tables' => [],
                ],
                'artifacts' => [
                    'uploaded' => [],
                    'downloaded' => [],
                ],
                'variables' => [],
            ],
            $jobResult->jsonSerialize(),
        );
    }

    private function getTableInfo(): array
    {
        return [
            'first' => new TableInfo([
                'id' => 'in.c-main.my-first-table',
                'displayName' => 'My first table',
                'name' => 'my-first-table',
                'lastImportDate' => '2021-02-12T10:36:15+0100',
                'lastChangeDate' => '2021-12-12T10:36:15+0100',
                'columns' => [
                    'first',
                    'second',
                ],
                'columnMetadata' => [
                    'second' => [
                        [
                            'id' => '1234567',
                            'key' => 'KBC.datatype.basetype',
                            'value' => 'INTEGER',
                            'provider' => 'user',
                            'timestamp' => '2019-08-14T16:55:34+0200',
                        ],
                    ],
                ],
            ]),
            'second' => new TableInfo([
                'id' => 'in.c-main.my-second-table',
                'displayName' => 'My Second table',
                'name' => 'my-second-table',
                'lastImportDate' => '2011-02-12T10:36:15+0100',
                'lastChangeDate' => '2011-12-12T10:36:15+0100',
                'columns' => [
                    'third',
                    'fourth',
                ],
                'columnMetadata' => [
                    'fourth' => [
                        [
                            'id' => '7654321',
                            'key' => 'KBC.datatype.basetype',
                            'value' => 'VARCHAR',
                            'provider' => 'user',
                            'timestamp' => '2020-08-14T16:55:34+0200',
                        ],
                    ],
                    'third' => [
                        [
                            'id' => '9876543',
                            'key' => 'KBC.datatype.basetype',
                            'value' => 'TIMESTAMP',
                            'provider' => 'system',
                            'timestamp' => '2020-09-14T16:55:34+0200',
                        ],
                    ],
                ],
            ]),
            'third' => new TableInfo([
                'id' => 'in.c-main.my-third-table',
                'displayName' => 'My Third table',
                'name' => 'my-third-table',
                'lastImportDate' => '2021-03-12T10:36:15+0100',
                'lastChangeDate' => '2021-13-12T10:36:15+0100',
                'columns' => [
                    'fifth',
                    'sixth',
                ],
                'columnMetadata' => [
                    'fifth' => [
                        [
                            'id' => '13579',
                            'key' => 'KBC.datatype.basetype',
                            'value' => 'VARCHAR',
                            'provider' => 'system',
                            'timestamp' => '2020-08-14T16:55:34+0200',
                        ],
                    ],
                    'sixth' => [
                        [
                            'id' => '97531',
                            'key' => 'KBC.datatype.basetype',
                            'value' => 'INT',
                            'provider' => 'user',
                            'timestamp' => '2020-09-14T16:55:34+0200',
                        ],
                    ],
                ],
            ]),
            'fourth' => new TableInfo([
                'id' => 'in.c-main.my-fourth-table',
                'displayName' => 'My Fourth table',
                'name' => 'my-fourth-table',
                'lastImportDate' => '2001-03-12T10:36:15+0100',
                'lastChangeDate' => '2001-13-12T10:36:15+0100',
                'columns' => [
                    'seventh',
                    'eight',
                ],
            ]),
            'fifth' => new TableInfo([
                'id' => 'in.c-main.my-fifth-table',
                'displayName' => 'My Fifth table',
                'name' => 'my-fifth-table',
                'lastImportDate' => '2001-03-12T10:36:15+0100',
                'lastChangeDate' => '2001-13-12T10:36:15+0100',
                'columns' => [
                    'zero',
                    'one',
                ],
            ]),
        ];
    }
}

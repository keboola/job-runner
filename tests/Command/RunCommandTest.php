<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RunCommand;
use App\CreditsCheckerFactory;
use App\JobDefinitionFactory;
use Generator;
use Keboola\BillingApi\CreditsChecker;
use Keboola\Csv\CsvFile;
use Keboola\ErrorControl\Monolog\LogProcessor;
use Keboola\ErrorControl\Uploader\UploaderFactory;
use Keboola\JobQueueInternalClient\Client;
use Keboola\JobQueueInternalClient\Exception\StateTransitionForbiddenException;
use Keboola\JobQueueInternalClient\JobFactory\Job;
use Keboola\JobQueueInternalClient\JobFactory\JobInterface;
use Keboola\JobQueueInternalClient\JobPatchData;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Metadata;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\StorageApi\Options\Metadata\TableMetadataUpdateOptions;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Keboola\StorageApiBranch\Factory\StorageClientPlainFactory;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class RunCommandTest extends AbstractCommandTest
{
    private StorageClient $storageClient;

    private array $tokenData;

    public function setUp(): void
    {
        parent::setUp();
        putenv('AWS_ACCESS_KEY_ID=' . getenv('TEST_AWS_ACCESS_KEY_ID'));
        putenv('AWS_SECRET_ACCESS_KEY=' . getenv('TEST_AWS_SECRET_ACCESS_KEY'));
        putenv('AZURE_TENANT_ID=' . getenv('TEST_AZURE_TENANT_ID'));
        putenv('AZURE_CLIENT_ID=' . getenv('TEST_AZURE_CLIENT_ID'));
        putenv('AZURE_CLIENT_SECRET=' . getenv('TEST_AZURE_CLIENT_SECRET'));
        putenv('JOB_ID=');
        putenv('STORAGE_API_TOKEN=' . getenv('TEST_STORAGE_API_TOKEN'));

        $this->storageClient = new StorageClient([
            'url' => getenv('STORAGE_API_URL'),
            'token' => getenv('TEST_STORAGE_API_TOKEN'),
        ]);
        $this->tokenData = $this->storageClient->verifyToken();
    }

    public function testExecuteFailure(): void
    {
        $kernel = static::createKernel();
        $application = new Application($kernel);
        $command = $application->find('app:run');

        $reflectionProperty = new ReflectionProperty($command, 'logger');
        $reflectionProperty->setAccessible(true);
        /** @var Logger $logger */
        $logger = $reflectionProperty->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        $records = $testHandler->getRecords();
        $errorRecord = [];
        foreach ($records as $record) {
            if ($record['message'] === 'Job "" ended with application error: "Internal Server Error occurred."'
            ) {
                $errorRecord = $record;
            }
        }
        self::assertArrayHasKey('context', $errorRecord, print_r($records, true));
        self::assertIsString($errorRecord['context']['attachment']);
        self::assertStringStartsWith('https://connection', $errorRecord['context']['attachment']);
        self::assertTrue($testHandler->hasErrorThatContains('Failed to save result for job ""'));
        self::assertEquals(0, $ret);
    }

    public function testExecuteSuccessWithInputInResult(): void
    {
        ['newJobFactory' => $newJobFactory, 'client' => $client] = $this->getJobFactoryAndClient();

        $tableId = $this->initTestDataTable();
        $job = $newJobFactory->createNewJob([
            'componentId' => 'keboola.runner-config-test',
            '#tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
            'mode' => 'run',
            'parentRunId' => '123',
            'configData' => [
                'parameters' => [
                    'operation' => 'unsafe-dump-config',
                    'arbitrary' => [
                        '#foo' => 'bar',
                    ],
                ],
                'storage' => [
                    'input' => [
                        'tables' => [
                            [
                                'source' => $tableId,
                                'destination' => 'someTable.csv',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $job = $client->createJob($job);
        putenv('JOB_ID=' . $job->getId());
        self::assertStringStartsWith('KBC::ProjectSecure', $job->getConfigData()['parameters']['arbitrary']['#foo']);
        $kernel = static::createKernel();
        $application = new Application($kernel);

        $command = $application->find('app:run');

        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        $jobRecord = [];
        foreach ($testHandler->getRecords() as $record) {
            if ($record['message'] === 'Output mapping done.') {
                $jobRecord = $record;
            }
        }
        self::assertNotEmpty($jobRecord);
        self::assertEquals('keboola.runner-config-test', $jobRecord['component']);
        self::assertEquals($job->getId(), $jobRecord['runId']);
        self::assertTrue($testHandler->hasInfoThatContains(
            '" p a r a m e t e r s " : { " a r b i t r a r y " : { " # f o o " : " b a r " }'
        ));
        self::assertFalse($testHandler->hasInfoThatContains('Job is already running'));
        self::assertTrue($testHandler->hasInfoThatContains('Runner ID'));
        self::assertTrue($testHandler->hasInfoThatContains('Running job "' . $job->getId() . '".'));
        self::assertTrue($testHandler->hasInfoThatContains('Job "' . $job->getId() . '" execution finished.'));
        self::assertEquals(0, $ret);

        $events = $this->storageClient->listEvents(['runId' => $job->getId()]);
        $messages = array_column($events, 'message');
        // event from storage
        self::assertContains('Downloaded file in.c-main.someTable.csv.gz', $messages);
        // event from runner
        self::assertContains('Running component keboola.runner-config-test (row 1 of 1)', $messages);

        /** @var Job $finishedJob */
        $finishedJob = $client->getJob($job->getId());
        $result = $finishedJob->getResult();
        self::assertArrayHasKey('output', $result);
        self::assertArrayHasKey('tables', $result['output']);
        self::assertSame([], $result['output']['tables']);

        self::assertArrayHasKey('input', $result);
        self::assertArrayHasKey('tables', $result['input']);
        $inputTable = reset($result['input']['tables']);
        self::assertSame([
            'id' => $tableId,
            'name' => 'someTable',
            'columns' => [
                [
                    'name' => 'a',
                ],
                [
                    'name' => 'b',
                ],
            ],
            'displayName' => 'someTable',
        ], $inputTable);
        self::assertSame(
            [
                'storage' => [
                    'inputTablesBytesSum' => 177,
                    'outputTablesBytesSum' => 0,
                ],
                'backend' => [
                    'size' => null,
                    'containerSize' => 'small',
                    'context' => $this->tokenData['owner']['id'] . '-application',
                ],
            ],
            $finishedJob->getMetrics()->jsonSerialize()
        );
    }

    public function testExecuteSuccessWithLocalInputOutputInResult(): void
    {
        ['newJobFactory' => $newJobFactory, 'client' => $client] = $this->getJobFactoryAndClient();

        $bucketId = $this->recreateTestBucket();
        $tableId1 = $this->createTestTable($bucketId, 'someTable', ['a', 'b']);
        $tableId2 = $this->createTestTable($bucketId, 'someTableNumeric', ['4', '0']);

        try {
            $this->storageClient->dropBucket('out.c-main', ['force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        $tableOut1Manifest = json_encode([
            'destination' => 'out.c-main.modified',
            'column_metadata' => [
                'a' => [
                    [
                        'key' => 'testKey',
                        'value' => 'testA',
                    ],
                ],
                'b' => [
                    [
                        'key' => 'testKey',
                        'value' => 'testB',
                    ],
                ],
            ],
        ]);

        $tableOut2Manifest = json_encode([
            'destination' => 'out.c-main.numericModified',
            'column_metadata' => [
                '0' => [
                    [
                        'key' => 'testKey',
                        'value' => 'test0',
                    ],
                ],
                '4' => [
                    [
                        'key' => 'testKey',
                        'value' => 'test4',
                    ],
                ],
            ],
        ]);

        $jobData = [
            'componentId' => 'keboola.python-transformation',
            '#tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
            'mode' => 'run',
            'backend' => [
                'context' => '123_transformation',
            ],
            'configData' => [
                'storage' => [
                    'input' => [
                        'tables' => [
                            [
                                'source' => $tableId1,
                                'destination' => 'source.csv',
                            ],
                            [
                                'source' => $tableId2,
                                'destination' => 'sourceNumeric.csv',
                            ],
                        ],
                    ],
                ],
                'parameters' => [
                    'plain' => 'not-secret',
                    'script' => [
                        'import csv',
                        'import json',
                        'with open("/data/in/tables/source.csv", mode="rt", encoding="utf-8") as in_file, ' .
                        'open("/data/out/tables/destination.csv", mode="wt", encoding="utf-8") as out_file:',
                        '   lazy_lines = (line.replace("\0", "") for line in in_file)',
                        '   reader = csv.DictReader(lazy_lines, dialect="kbc")',
                        '   writer = csv.DictWriter(out_file, dialect="kbc", fieldnames=reader.fieldnames)',
                        '   writer.writeheader()',
                        '   for row in reader:',
                        '      writer.writerow({"a": row["a"], "b": row["b"]})',
                        '   writer.writerow({"a": "newA", "b": "newB"})',
                        'with open("/data/out/tables/destination.csv.manifest", "w") as out_file_manifest:',
                        '   json.dump(' . $tableOut1Manifest . ', out_file_manifest)',
                        'with open("/data/in/tables/sourceNumeric.csv", mode="rt", encoding="utf-8") as in_file, ' .
                        'open("/data/out/tables/destinationNumeric.csv", mode="wt", encoding="utf-8") as out_file:',
                        '   lazy_lines = (line.replace("\0", "") for line in in_file)',
                        '   reader = csv.DictReader(lazy_lines, dialect="kbc")',
                        '   writer = csv.DictWriter(out_file, dialect="kbc", fieldnames=reader.fieldnames)',
                        '   writer.writeheader()',
                        '   for row in reader:',
                        '      writer.writerow({"4": row["4"], "0": row["0"]})',
                        '   writer.writerow({"4": "new4", "0": "new0"})',
                        'with open("/data/out/tables/destinationNumeric.csv.manifest", "w") as out_file_manifest:',
                        '   json.dump(' . $tableOut2Manifest . ', out_file_manifest)',
                    ],
                ],
            ],
        ];

        $job = $newJobFactory->createNewJob($jobData);
        $job = $client->createJob($job);

        putenv('JOB_ID=' . $job->getId());
        $kernel = static::createKernel();
        $application = new Application($kernel);

        $command = $application->find('app:run');

        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $property = new ReflectionProperty($command, 'storageClientFactory');
        $property->setAccessible(true);
        /** @var StorageClientPlainFactory $baseFactory */
        $baseFactory = $property->getValue($command);
        $baseOptions = $baseFactory->getClientOptionsReadOnly();

        $storageClientFactoryMock = $this->getMockBuilder(StorageClientPlainFactory::class)
            ->setConstructorArgs([$baseOptions])
            ->getMock();
        $storageClientFactoryMock
            ->expects(self::exactly(2))
            ->method('createClientWrapper')
            ->willReturnCallback(function (ClientOptions $options) use ($baseFactory): ClientWrapper {
                $backendConfiguration = $options->getBackendConfiguration();
                self::assertNotNull($backendConfiguration);
                self::assertSame('{"context":"123_transformation"}', $backendConfiguration->toJson());
                return $baseFactory->createClientWrapper($options);
            })
        ;

        $property->setValue($command, $storageClientFactoryMock);

        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        $jobRecord = [];
        foreach ($testHandler->getRecords() as $record) {
            if ($record['message'] === 'Output mapping done.') {
                $jobRecord = $record;
            }
        }

        self::assertNotEmpty($jobRecord);
        self::assertEquals('keboola.python-transformation', $jobRecord['component']);
        self::assertEquals($job->getId(), $jobRecord['runId']);
        self::assertFalse($testHandler->hasInfoThatContains('Job is already running'));
        self::assertTrue($testHandler->hasInfoThatContains('Running job "' . $job->getId() . '".'));
        self::assertTrue($testHandler->hasInfoThatContains('Job "' . $job->getId() . '" execution finished.'));
        self::assertEquals(0, $ret);

        $events = $this->storageClient->listEvents(['runId' => $job->getRunId()]);
        $messages = array_column($events, 'message');
        // event from storage
        self::assertContains('Downloaded file in.c-main.someTable.csv.gz', $messages);
        // event from storage
        self::assertContains('Downloaded file in.c-main.someTableNumeric.csv.gz', $messages);
        // event from runner
        self::assertContains('Running component keboola.python-transformation (row 1 of 1)', $messages);
        // event from storage
        self::assertContains('Imported table out.c-main.modified', $messages);
        // event from storage
        self::assertContains('Imported table out.c-main.numericModified', $messages);

        /** @var Job $finishedJob */
        $finishedJob = $client->getJob($job->getId());
        $result = $finishedJob->getResult();

        self::assertArrayHasKey('output', $result);
        self::assertArrayHasKey('tables', $result['output']);
        self::assertCount(2, $result['output']['tables']);

        $outputTables = $result['output']['tables'];
        usort($outputTables, function ($a, $b) {
            return strcmp($a['id'], $b['id']);
        });

        [$table1, $table2] = $outputTables;
        $this->assertInputOutputTable($table1, 'out.c-main.modified', 'modified', ['a', 'b']);
        $this->assertInputOutputTable($table2, 'out.c-main.numericModified', 'numericModified', ['4', '0']);

        self::assertArrayHasKey('input', $result);
        self::assertArrayHasKey('tables', $result['input']);
        self::assertCount(2, $result['input']['tables']);

        $inputTables = $result['input']['tables'];
        usort($inputTables, function ($a, $b) {
            return strcmp($a['id'], $b['id']);
        });

        [$table1, $table2] = $inputTables;
        $this->assertInputOutputTable($table1, 'in.c-main.someTable', 'someTable', ['a', 'b']);
        $this->assertInputOutputTable($table2, 'in.c-main.someTableNumeric', 'someTableNumeric', ['4', '0']);

        self::assertSame(
            [
                'storage' => [
                    'inputTablesBytesSum' => 361,
                    'outputTablesBytesSum' => 123,
                ],
                'backend' => [
                    'size' => null,
                    'containerSize' => 'small',
                    'context' => '123_transformation',
                ],
            ],
            $finishedJob->getMetrics()->jsonSerialize()
        );

        $this->assertOutputTableMetadata('out.c-main.modified', ['a', 'b']);
        $this->assertOutputTableData(
            'out.c-main.modified',
            [
                [
                    [
                        'columnName' => 'a',
                        'value' => 'dataA',
                        'isTruncated' => false,
                    ],
                    [
                        'columnName' => 'b',
                        'value' => 'dataB',
                        'isTruncated' => false,
                    ],
                ],
                [
                    [
                        'columnName' => 'a',
                        'value' => 'newA',
                        'isTruncated' => false,
                    ],
                    [
                        'columnName' => 'b',
                        'value' => 'newB',
                        'isTruncated' => false,
                    ],
                ],
            ]
        );

        $this->assertOutputTableMetadata('out.c-main.numericModified', ['4', '0']);
        $this->assertOutputTableData(
            'out.c-main.numericModified',
            [
                [
                    [
                        'columnName' => '4',
                        'value' => 'data4',
                        'isTruncated' => false,
                    ],
                    [
                        'columnName' => '0',
                        'value' => 'data0',
                        'isTruncated' => false,
                    ],
                ],
                [
                    [
                        'columnName' => '4',
                        'value' => 'new4',
                        'isTruncated' => false,
                    ],
                    [
                        'columnName' => '0',
                        'value' => 'new0',
                        'isTruncated' => false,
                    ],
                ],
            ]
        );
    }

    public function testExecuteFailureWithLocalInputOutputInMetrics(): void
    {
        ['newJobFactory' => $newJobFactory, 'client' => $client] = $this->getJobFactoryAndClient();

        $tableId = $this->initTestDataTable();
        try {
            $this->storageClient->dropBucket('out.c-main', ['force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        $jobData = [
            'componentId' => 'keboola.python-transformation',
            '#tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
            'mode' => 'run',
            'backend' => [
                'context' => '123_transformation',
            ],
            'configData' => [
                'storage' => [
                    'input' => [
                        'tables' => [
                            [
                                'source' => $tableId,
                                'destination' => 'source.csv',
                            ],
                        ],
                    ],
                    'output' => [
                        'tables' => [
                            [
                                'source' => 'destination-does-not-exists.csv',
                                'destination' => 'out.c-main.modified',
                            ],
                        ],
                    ],
                ],
                'parameters' => [
                    'plain' => 'not-secret',
                    'script' => [
                        'import csv',
                        'with open("/data/in/tables/source.csv", mode="rt", encoding="utf-8") as in_file, ' .
                        'open("/data/out/tables/destination.csv", mode="wt", encoding="utf-8") as out_file:',
                        '   lazy_lines = (line.replace("\0", "") for line in in_file)',
                        '   reader = csv.DictReader(lazy_lines, dialect="kbc")',
                        '   writer = csv.DictWriter(out_file, dialect="kbc", fieldnames=reader.fieldnames)',
                        '   writer.writeheader()',
                        '   for row in reader:',
                        '      writer.writerow({"a": row["a"], "b": row["b"]})',
                    ],
                ],
            ],
        ];

        $job = $newJobFactory->createNewJob($jobData);
        $job = $client->createJob($job);
        putenv('JOB_ID=' . $job->getId());
        $kernel = static::createKernel();
        $application = new Application($kernel);

        $command = $application->find('app:run');

        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        self::assertFalse($testHandler->hasInfoThatContains('Job is already running'));
        self::assertTrue($testHandler->hasInfoThatContains('Running job "' . $job->getId() . '".'));
        self::assertTrue($testHandler->hasErrorThatContains('Job "' . $job->getId() . '" ended with user error'));
        self::assertEquals(0, $ret);

        /** @var Job $finishedJob */
        $finishedJob = $client->getJob($job->getId());
        self::assertSame('error', $finishedJob->getStatus());
        $result = $finishedJob->getResult();

        self::assertArrayHasKey('message', $result);
        self::assertSame('Table sources not found: "destination-does-not-exists.csv"', $result['message']);

        self::assertArrayHasKey('output', $result);
        self::assertArrayHasKey('tables', $result['output']);
        self::assertSame([], $result['output']['tables']);
        self::assertArrayHasKey('input', $result);
        self::assertArrayHasKey('tables', $result['input']);
        self::assertSame([], $result['input']['tables']);

        self::assertSame(
            [
                'storage' => [
                    'inputTablesBytesSum' => 177,
                    'outputTablesBytesSum' => 0,
                ],
                'backend' => [
                    'size' => null,
                    'containerSize' => 'small',
                    'context' => '123_transformation',
                ],
            ],
            $finishedJob->getMetrics()->jsonSerialize()
        );
    }

    public function testExecuteSuccessWithCopyInputOutputInResult(): void
    {
        ['newJobFactory' => $newJobFactory, 'client' => $client] = $this->getJobFactoryAndClient();

        $tableId = $this->initTestDataTable();
        try {
            $this->storageClient->dropBucket('out.c-main', ['force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        $job = $newJobFactory->createNewJob([
            'componentId' => 'keboola.runner-workspace-test',
            '#tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
            'mode' => 'run',
            'configData' => [
                'parameters' => [
                    'operation' => 'copy',
                ],
                'storage' => [
                    'input' => [
                        'tables' => [
                            [
                                'source' => $tableId,
                                'destination' => 'someTable',
                            ],
                        ],
                    ],
                    'output' => [
                        'tables' => [
                            [
                                'source' => 'someTable-copy',
                                'destination' => 'out.c-main.someTable',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $job = $client->createJob($job);
        putenv('JOB_ID=' . $job->getId());
        $kernel = static::createKernel();
        $application = new Application($kernel);

        $command = $application->find('app:run');

        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        $jobRecord = [];
        foreach ($testHandler->getRecords() as $record) {
            if ($record['message'] === 'Output mapping done.') {
                $jobRecord = $record;
            }
        }

        self::assertNotEmpty($jobRecord);
        self::assertEquals('keboola.runner-workspace-test', $jobRecord['component']);
        self::assertEquals($job->getId(), $jobRecord['runId']);
        self::assertFalse($testHandler->hasInfoThatContains('Job is already running'));
        self::assertTrue($testHandler->hasInfoThatContains('Running job "' . $job->getId() . '".'));
        self::assertTrue($testHandler->hasInfoThatContains('Job "' . $job->getId() . '" execution finished.'));
        self::assertEquals(0, $ret);

        /** @var Job $job */
        $job = $client->getJob($job->getId());
        self::assertSame('success', $job->getStatus());

        $result = $job->getResult();
        self::assertArrayHasKey('output', $result);
        self::assertArrayHasKey('tables', $result['output']);
        $outputTable = reset($result['output']['tables']);
        self::assertSame([
            'id' => 'out.c-main.someTable',
            'name' => 'someTable',
            'columns' => [
                [
                    'name' => 'a',
                ],
                [
                    'name' => 'b',
                ],
            ],
            'displayName' => 'someTable',
        ], $outputTable);

        self::assertArrayHasKey('input', $result);
        self::assertArrayHasKey('tables', $result['input']);
        $inputTable = reset($result['input']['tables']);
        self::assertSame([
            'id' => $tableId,
            'name' => 'someTable',
            'columns' => [
                [
                    'name' => 'a',
                ],
                [
                    'name' => 'b',
                ],
            ],
            'displayName' => 'someTable',
        ], $inputTable);

        self::assertSame('small', $job->getMetrics()->getBackendSize());
        self::assertSame(
            [
                'storage' => [
                    'inputTablesBytesSum' => 1536,
                    'outputTablesBytesSum' => 1536,
                ],
                'backend' => [
                    'size' => 'small',
                    'containerSize' => 'small',
                    'context' => $this->tokenData['owner']['id'] . '-other',
                ],
            ],
            $job->getMetrics()->jsonSerialize()
        );
    }

    public function testExecuteVariablesSharedCode(): void
    {
        $storageClientFactory = new StorageClientPlainFactory(new ClientOptions(
            (string) getenv('STORAGE_API_URL'),
            (string) getenv('TEST_STORAGE_API_TOKEN')
        ));
        $storageClient = $storageClientFactory->createClientWrapper(new ClientOptions())->getBasicClient();
        $componentsApi = new Components($storageClient);
        $configurationApi = new Configuration();
        $configurationApi->setComponentId('keboola.shared-code');
        $configurationApi->setName('test-code');
        $sharedCodeId = $componentsApi->addConfiguration($configurationApi)['id'];
        $configurationApi->setConfigurationId($sharedCodeId);
        $configurationRowApi = new ConfigurationRow($configurationApi);
        $configurationRowApi->setRowId('code-id');
        $configurationRowApi->setConfiguration(['code_content' => 'my-shared-code']);
        $componentsApi->addConfigurationRow($configurationRowApi);
        $configurationApi = new Configuration();
        $configurationApi->setComponentId('keboola.variables');
        $configurationApi->setName('test-variables');
        $configurationApi->setConfiguration(['variables' => [['name' => 'fooVar', 'type' => 'string']]]);
        $variablesId = $componentsApi->addConfiguration($configurationApi)['id'];
        $configurationApi = new Configuration();
        $configurationApi->setComponentId('keboola.runner-config-test');
        $configurationApi->setName('test-configuration');
        $configurationApi->setConfiguration(
            [
                'parameters' => [
                    'operation' => 'dump-config',
                    'arbitrary' => [
                        'variable' => 'bar {{fooVar}}',
                        'sharedCode' => ['{{code-id}}'],
                    ],
                ],
                'variables_id' => $variablesId,
                'shared_code_id' => $sharedCodeId,
                'shared_code_row_ids' => ['code-id'],
            ]
        );
        $configurationId = $componentsApi->addConfiguration($configurationApi)['id'];
        ['newJobFactory' => $newJobFactory, 'client' => $client] = $this->getJobFactoryAndClient();

        try {
            $job = $newJobFactory->createNewJob([
                'componentId' => 'keboola.runner-config-test',
                '#tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
                'mode' => 'forceRun',
                'configId' => $configurationId,
                'variableValuesData' => [
                    'values' => [
                        [
                            'name' => 'fooVar',
                            'value' => 'Kochba',
                        ],
                    ],
                ],
            ]);
            $job = $client->createJob($job);
            putenv('JOB_ID=' . $job->getId());
            $kernel = static::createKernel();
            $application = new Application($kernel);

            $command = $application->find('app:run');

            $property = new ReflectionProperty($command, 'logger');
            $property->setAccessible(true);
            /** @var Logger $logger */
            $logger = $property->getValue($command);
            $testHandler = new TestHandler();
            $logger->pushHandler($testHandler);

            $commandTester = new CommandTester($command);
            $ret = $commandTester->execute([
                'command' => $command->getName(),
            ]);

            $code = '';
            foreach ($testHandler->getRecords() as $record) {
                if (str_contains($record['message'], 'arbitrary')) {
                    $code = $record['message'];
                }
            }
            self::assertStringStartsWith('Config: {', $code);
            $record = substr($code, 8);
            $data = json_decode($record, true);
            self::assertEquals(
                [
                    'parameters' => [
                        'arbitrary' => [
                            'variable' => 'bar Kochba',
                            'sharedCode' => ['my-shared-code'],
                        ],
                        'operation' => 'dump-config',
                    ],
                    'variables_id' => $variablesId,
                    'shared_code_id' => $sharedCodeId,
                    'shared_code_row_ids' => ['code-id'],
                    'image_parameters' => [],
                    'action' => 'run',
                    'storage' => [],
                    'authorization' => [
                        'context' => $this->tokenData['owner']['id'] . '-application',
                    ],
                ],
                $data
            );
            self::assertFalse($testHandler->hasInfoThatContains('Job is already running'));
            self::assertTrue($testHandler->hasInfoThatContains('Running job "' . $job->getId() . '".'));
            self::assertTrue($testHandler->hasInfoThatContains('Job "' . $job->getId() . '" execution finished.'));
            self::assertEquals(0, $ret);
        } finally {
            $componentsApi->deleteConfiguration('keboola.runner-config-test', $configurationId);
            $componentsApi->deleteConfiguration('keboola.shared-code', $sharedCodeId);
            $componentsApi->deleteConfiguration('keboola.variables', $variablesId);
        }
    }

    public function testExecuteUnEncryptedJobData(): void
    {
        $storageClientFactory = new StorageClientPlainFactory(
            new ClientOptions((string) getenv('STORAGE_API_URL'))
        );

        [
            'existingJobFactory' => $existingJobFactory,
            'objectEncryptor' => $objectEncryptor,
            'client' => $client,
        ] = $this->getJobFactoryAndClient();

        $storageClient = $storageClientFactory->createClientWrapper(
            new ClientOptions(
                null,
                (string) getenv('TEST_STORAGE_API_TOKEN')
            )
        )->getBasicClient();
        $tokenInfo = $storageClient->verifytoken();
        // fabricate an erroneous job which contains unencrypted values
        $id = $storageClient->generateId();
        $job = $existingJobFactory->loadFromExistingJobData([
            'id' => $id,
            'runId' => $id,
            'componentId' => 'keboola.runner-config-test',
            'projectId' => $tokenInfo['owner']['id'],
            'projectName' => $tokenInfo['owner']['name'],
            'tokenDescription' => $tokenInfo['description'],
            'tokenId' => $tokenInfo['id'],
            '#tokenString' => $objectEncryptor->encryptForProject(
                (string) getenv('TEST_STORAGE_API_TOKEN'),
                'keboola.runner-config-test',
                (string) $tokenInfo['owner']['id'],
            ),
            'status' => JobInterface::STATUS_CREATED,
            'desiredStatus' => JobInterface::DESIRED_STATUS_PROCESSING,
            'mode' => 'run',
            'configId' => 'dummy',
            'configData' => [
                'parameters' => [
                    'operation' => 'unsafe-dump-config',
                    'arbitrary' => [
                        '#foo' => 'bar',
                    ],
                ],
            ],
        ]);
        // check that the encrypted value was NOT encrypted
        self::assertEquals('bar', $job->getConfigData()['parameters']['arbitrary']['#foo']);
        $job = $client->createJob($job);
        putenv('JOB_ID=' . $job->getId());
        $kernel = static::createKernel();
        $application = new Application($kernel);

        $command = $application->find('app:run');

        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        self::assertTrue($testHandler->hasErrorThatContains(sprintf(
            'Job "%s" ended with encryption error: '.
            '"Invalid cipher text for key #foo Value "bar" is not an encrypted value."',
            $job->getId(),
        )));
        self::assertTrue($testHandler->hasInfoThatContains('Running job "' . $job->getId() . '".'));
        self::assertEquals(0, $ret);
    }

    public function executeSkipData(): Generator
    {
        yield 'already running job' => [
            JobInterface::STATUS_PROCESSING,
            'Job "%s" is already running.',
        ];
        yield 'already cancelled job' => [
            JobInterface::STATUS_CANCELLED,
            'Job "%s" was already executed or is cancelled.',
        ];
        yield 'already errored job' => [
            JobInterface::STATUS_ERROR,
            'Job "%s" was already executed or is cancelled.',
        ];
    }

    /**
     * @dataProvider executeSkipData
     */
    public function testExecuteSkip(string $initialJobStatus, string $expectedInfoMessage): void
    {
        ['newJobFactory' => $newJobFactory, 'client' => $client] = $this->getJobFactoryAndClient();

        $job = $newJobFactory->createNewJob([
            'componentId' => 'keboola.ex-http',
            '#tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
            'mode' => 'run',
            'configData' => [
                'storage' => [],
                'parameters' => [
                    'baseUrl' => 'https://help.keboola.com/',
                    'path' => 'tutorial/opportunity.csv',
                ],
            ],
        ]);

        // set the job to processing, the job will succeed but do nothing
        $job = $client->createJob($job);
        $job = $client->patchJob($job->getId(), (new JobPatchData())->setStatus($initialJobStatus));
        putenv('JOB_ID=' . $job->getId());

        $kernel = static::createKernel();
        $application = new Application($kernel);
        $command = $application->find('app:run');

        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute(
            ['command' => $command->getName()],
            ['verbosity' => OutputInterface::VERBOSITY_DEBUG,
            'capture_stderr_separately' => true]
        );

        self::assertCount(2, $testHandler->getRecords());
        self::assertTrue($testHandler->hasInfoThatContains('Running job "' . $job->getId() . '".'));
        self::assertTrue($testHandler->hasInfoThatContains(sprintf($expectedInfoMessage, $job->getId())));
        self::assertEquals(0, $ret);

        $job = $client->getJob($job->getId());
        self::assertSame($initialJobStatus, $job->getStatus());
    }

    public function testExecuteCustomBackendConfig(): void
    {
        ['newJobFactory' => $newJobFactory, 'client' => $client] = $this->getJobFactoryAndClient();

        $job = $newJobFactory->createNewJob([
            'componentId' => 'keboola.runner-config-test',
            '#tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
            'mode' => 'run',
            'configData' => [
                'parameters' => [
                    'operation' => 'unsafe-dump-config',
                    'arbitrary' => [
                        '#foo' => 'bar',
                    ],
                ],
                'runtime' => [
                    'backend' => [
                        'type' => 'custom',
                    ],
                ],
            ],
        ]);
        $job = $client->createJob($job);
        putenv('JOB_ID=' . $job->getId());
        $kernel = static::createKernel();
        $application = new Application($kernel);

        $command = $application->find('app:run');

        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute(
            ['command' => $command->getName()],
            ['verbosity' => OutputInterface::VERBOSITY_DEBUG,
            'capture_stderr_separately' => true]
        );

        self::assertTrue($testHandler->hasInfoThatContains('Running job "' . $job->getId() . '".'));
        self::assertTrue($testHandler->hasInfoThatContains('Job "' . $job->getId() . '" execution finished.'));
        self::assertEquals(0, $ret);
    }

    public function testExecuteCreditsCheck(): void
    {
        $tokenInfo = $this->storageClient->verifyToken();
        $tokenInfo['owner']['features'] = 'pay-as-you-go';

        $creditsCheckerMock = $this->createMock(CreditsChecker::class);
        $creditsCheckerMock->method('hasCredits')->willReturn(false);

        $storageClientMock = $this->getMockBuilder(StorageClient::class)
            ->setConstructorArgs([[
                'url' => getenv('STORAGE_API_URL'),
                'token' => getenv('TEST_STORAGE_API_TOKEN'),
            ]])
            ->onlyMethods(['verifyToken'])
            ->getMock();
        $storageClientMock->method('verifyToken')->willReturn($tokenInfo);
        $creditsCheckerFactoryMock = $this->createMock(CreditsCheckerFactory::class);
        $creditsCheckerFactoryMock->method('getCreditsChecker')->willReturn($creditsCheckerMock);
        $clientWrapperMock = $this->createMock(ClientWrapper::class);
        $clientWrapperMock->method('getBasicClient')->willReturn($storageClientMock);
        $clientWrapperMock->method('getBranchClientIfAvailable')->willReturn($storageClientMock);
        $storageClientFactoryMock = $this->createMock(StorageClientPlainFactory::class);
        $storageClientFactoryMock->method('createClientWrapper')->willReturn($clientWrapperMock);

        ['newJobFactory' => $newJobFactory, 'client' => $client] = $this->getJobFactoryAndClient();

        $job = $newJobFactory->createNewJob([
            'componentId' => 'keboola.runner-config-test',
            '#tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
            'mode' => 'run',
            'configData' => [],
        ]);
        $job = $client->createJob($job);
        putenv('JOB_ID=' . $job->getId());
        $kernel = static::createKernel();
        $application = new Application($kernel);

        $command = $application->find('app:run');
        $reflection = new ReflectionProperty($command, 'creditsCheckerFactory');
        $reflection->setAccessible(true);
        $reflection->setValue($command, $creditsCheckerFactoryMock);
        $reflection = new ReflectionProperty($command, 'storageClientFactory');
        $reflection->setAccessible(true);
        $reflection->setValue($command, $storageClientFactoryMock);

        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        self::assertTrue($testHandler->hasInfoThatContains('Running job "' . $job->getId() . '".'));
        self::assertEquals(0, $ret);
        $failedJob = $client->getJob($job->getId());
        $result = $failedJob->getResult();
        unset($result['error']['exceptionId']);
        self::assertSame(
            [
                'error' => [
                    'type' => 'user',
                ],
                'input' => [
                    'tables' => [],
                ],
                'images' => [],
                'output' => [
                    'tables' => [],
                ],
                'message' => 'You do not have credits to run a job',
                'configVersion' => null,
            ],
            $result
        );
    }

    public function testExecuteStateTransitionError(): void
    {
        [
            'existingJobFactory' => $existingJobFactory,
            'objectEncryptor' => $objectEncryptor,
        ] = $this->getJobFactoryAndClient();

        $jobData = [
            'id' => '123',
            'runId' => '124',
            'projectId' => '219',
            'tokenId' => '567',
            'status' => 'created',
            'desiredStatus' => 'processing',
            'componentId' => 'keboola.runner-config-test',
            '#tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
            'mode' => 'run',
            'configId' => 'dummy',
            'configData' => [
                'parameters' => [
                    'operation' => 'unsafe-dump-config',
                    'arbitrary' => [
                        '#foo' => 'bar',
                    ],
                ],
            ],
        ];

        $jobData = $objectEncryptor->encryptGeneric($jobData);

        $mockQueueClient = $this->createMock(Client::class);
        $mockQueueClient
            ->expects(self::once())
            ->method('getJob')
            ->willReturn($existingJobFactory->loadFromExistingJobData($jobData));
        $mockQueueClient
            ->expects(self::once())
            ->method('patchJob')
            ->willReturn($existingJobFactory->loadFromExistingJobData(array_merge(
                $jobData,
                ['status' => 'processing']
            )));
        $mockQueueClient
            ->expects(self::once())
            ->method('postJobResult')
            ->willThrowException(new StateTransitionForbiddenException(
                'Invalid status transition of job "123" from ' .
                '"terminated (desired: terminating)" to "error desired: processing"'
            ));

        $logger = new Logger('job-runner-test');
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        $uploaderFactory = new UploaderFactory((string) getenv('STORAGE_API_URL'));
        $logProcessor = new LogProcessor($uploaderFactory, 'job-runner-test');
        $creditsCheckerFactory = new CreditsCheckerFactory();
        $jobDefinitionFactory = new JobDefinitionFactory();
        $storageApiFactory = new StorageClientPlainFactory(new ClientOptions(
            (string) getenv('STORAGE_API_URL'),
            (string) getenv('TEST_STORAGE_API_TOKEN'),
        ));

        $kernel = static::createKernel();
        $application = new Application($kernel);
        $application->add(new RunCommand(
            $logger,
            $logProcessor,
            $mockQueueClient,
            $creditsCheckerFactory,
            $storageApiFactory,
            $jobDefinitionFactory,
            $objectEncryptor,
            '123',
            (string) getenv('TEST_STORAGE_API_TOKEN'),
            []
        ));

        $command = $application->find('app:run');

        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        self::assertFalse($testHandler->hasErrorRecords());
        self::assertFalse($testHandler->hasCriticalRecords());
        self::assertFalse($testHandler->hasWarningRecords());

        self::assertTrue($testHandler->hasInfoThatContains(
            'Running job "123".'
        ));
        self::assertTrue($testHandler->hasNoticeThatContains(
            'Failed to save result for job "123". State transition forbidden:'
        ));
        self::assertEquals(0, $ret);
    }

    private function initTestDataTable(): string
    {
        $bucketId = $this->recreateTestBucket();
        return $this->createTestTable($bucketId, 'someTable', ['a', 'b']);
    }

    private function recreateTestBucket(): string
    {
        try {
            $this->storageClient->dropBucket('in.c-main', ['force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }

        return $this->storageClient->createBucket('main', StorageClient::STAGE_IN);
    }

    /**
     * @param array<int, string>$columnNames
     */
    private function createTestTable(string $bucketId, string $tableName, array $columnNames): string
    {
        $filePath = sprintf('%s/%s.csv', sys_get_temp_dir(), $tableName);
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $csv = new CsvFile($filePath);
        $csv->writeRow($columnNames);
        $csv->writeRow(array_map(
            function (string $columName): string {
                return  'data' . mb_strtoupper($columName);
            },
            $columnNames
        ));

        $tableId = $this->storageClient->createTableAsync($bucketId, $tableName, $csv);

        (new Metadata($this->storageClient))->postTableMetadataWithColumns(new TableMetadataUpdateOptions(
            $tableId,
            'runnerTests',
            null,
            array_map(
                function (): array {
                    return [
                        [
                            'key' => 'KBC.datatype.basetype',
                            'value' => 'STRING',
                        ],
                    ];
                },
                array_flip($columnNames)
            )
        ));

        return $tableId;
    }

    private function assertInputOutputTable(
        array $data,
        string $expectedTableId,
        string $expectedTableName,
        array $expectedColumns
    ): void {
        self::assertSame(
            [
                'id' => $expectedTableId,
                'name' => $expectedTableName,
                'columns' => array_map(function (string $columnName): array {
                    return [
                        'name' => $columnName,
                    ];
                }, $expectedColumns),
                'displayName' => $expectedTableName,
            ],
            $data
        );
    }

    private function assertOutputTableMetadata(string $tableId, array $expectedColumns): void
    {
        $tableMetadata = $this->storageClient->getTable($tableId);
        self::assertSame($expectedColumns, $tableMetadata['columns']);

        $columnMetadata = $tableMetadata['columnMetadata'];
        self::assertCount(count($expectedColumns), $tableMetadata['columnMetadata']);

        foreach ($expectedColumns as $columnName) {
            self::assertArrayHasKey($columnName, $columnMetadata);
            self::assertCount(1, $columnMetadata[$columnName]);
            $metadata = reset($columnMetadata[$columnName]);

            self::assertSame('testKey', $metadata['key']);
            self::assertSame(sprintf('test%s', mb_strtoupper($columnName)), $metadata['value']);
            self::assertSame('keboola.python-transformation', $metadata['provider']);
        }
    }

    private function assertOutputTableData(string $tableId, array $expectedRows): void
    {
        /** @var array $preview */
        $preview = $this->storageClient->getTableDataPreview($tableId, ['format' => 'json']);
        self::assertSame($expectedRows, $preview['rows']);
    }
}

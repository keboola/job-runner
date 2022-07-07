<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RunCommand;
use App\CreditsCheckerFactory;
use App\JobDefinitionFactory;
use App\StorageApiHandler;
use Keboola\BillingApi\CreditsChecker;
use Keboola\Csv\CsvFile;
use Keboola\ErrorControl\Monolog\LogProcessor;
use Keboola\ErrorControl\Uploader\UploaderFactory;
use Keboola\JobQueueInternalClient\Client;
use Keboola\JobQueueInternalClient\Exception\StateTransitionForbiddenException;
use Keboola\JobQueueInternalClient\JobFactory;
use Keboola\JobQueueInternalClient\JobFactory\Job;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\StorageApiBranch\ClientWrapper;
use Keboola\StorageApiBranch\Factory\ClientOptions;
use Keboola\StorageApiBranch\Factory\StorageClientPlainFactory;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class RunCommandTest extends AbstractCommandTest
{
    public function setUp(): void
    {
        parent::setUp();
        putenv('AWS_ACCESS_KEY_ID=' . getenv('TEST_AWS_ACCESS_KEY_ID'));
        putenv('AWS_SECRET_ACCESS_KEY=' . getenv('TEST_AWS_SECRET_ACCESS_KEY'));
        putenv('AZURE_TENANT_ID=' . getenv('TEST_AZURE_TENANT_ID'));
        putenv('AZURE_CLIENT_ID=' . getenv('TEST_AZURE_CLIENT_ID'));
        putenv('AZURE_CLIENT_SECRET=' . getenv('TEST_AZURE_CLIENT_SECRET'));
        putenv('JOB_ID=');
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
        self::assertStringStartsWith('https://connection', $errorRecord['context']['attachment']);
        self::assertTrue($testHandler->hasErrorThatContains('Failed to save result for job ""'));
        self::assertEquals(0, $ret);
    }

    public function testExecuteSuccessWithInputInResult(): void
    {
        list('factory' => $jobFactory, 'client' => $client) = $this->getJobFactoryAndClient();

        $storageClient = new StorageClient([
            'url' => getenv('STORAGE_API_URL'),
            'token' => getenv('TEST_STORAGE_API_TOKEN'),
        ]);
        try {
            $storageClient->dropBucket('in.c-main', ['force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
        $storageClient->createBucket('main', 'in');
        file_put_contents(sys_get_temp_dir() . '/someTable.csv', 'a,b');
        $csv = new CsvFile(sys_get_temp_dir() . '/someTable.csv');
        $storageClient->createTable('in.c-main', 'someTable', $csv);

        $job = $jobFactory->createNewJob([
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
                                'source' => 'in.c-main.someTable',
                                'destination' => 'someTable.csv',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $job = $client->createJob($job);
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

        putenv('JOB_ID=' . $job->getId());
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
        self::assertTrue($testHandler->hasInfoThatContains('Running job "' . $job->getId() . '".'));
        self::assertTrue($testHandler->hasInfoThatContains('Job "' . $job->getId() . '" execution finished.'));
        self::assertEquals(0, $ret);

        $storageClient = new StorageClient([
            'url' => getenv('STORAGE_API_URL'),
            'token' => getenv('TEST_STORAGE_API_TOKEN'),
        ]);
        $events = $storageClient->listEvents(['runId' => $job->getRunId()]);
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
            'id' => 'in.c-main.someTable',
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
                    'inputTablesBytesSum' => 14,
                ],
                'backend' => [
                    'size' => null,
                    'containerSize' => 'small',
                ],
            ],
            $finishedJob->getMetrics()->jsonSerialize()
        );
    }

    public function testExecuteSuccessWithInputOutputInResult(): void
    {
        list('factory' => $jobFactory, 'client' => $client) = $this->getJobFactoryAndClient();

        $storageClient = new StorageClient([
            'url' => getenv('STORAGE_API_URL'),
            'token' => getenv('TEST_STORAGE_API_TOKEN'),
        ]);
        try {
            $storageClient->dropBucket('in.c-main', ['force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
        try {
            $storageClient->dropBucket('out.c-main', ['force' => true]);
        } catch (ClientException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
        $storageClient->createBucket('main', 'in');
        file_put_contents(sys_get_temp_dir() . '/someTable.csv', 'a,b');
        $csv = new CsvFile(sys_get_temp_dir() . '/someTable.csv');
        $storageClient->createTable('in.c-main', 'someTable', $csv);

        $job = $jobFactory->createNewJob([
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
                                'source' => 'in.c-main.someTable',
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
        $kernel = static::createKernel();
        $application = new Application($kernel);

        $command = $application->find('app:run');

        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        putenv('JOB_ID=' . $job->getId());
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
            'id' => 'in.c-main.someTable',
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
        list('factory' => $jobFactory, 'client' => $client) = $this->getJobFactoryAndClient();
        try {
            $job = $jobFactory->createNewJob([
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
            $kernel = static::createKernel();
            $application = new Application($kernel);

            $command = $application->find('app:run');

            $property = new ReflectionProperty($command, 'logger');
            $property->setAccessible(true);
            /** @var Logger $logger */
            $logger = $property->getValue($command);
            $testHandler = new TestHandler();
            $logger->pushHandler($testHandler);

            putenv('JOB_ID=' . $job->getId());
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
                    'authorization' => [],
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
        list('factory' => $jobFactory, 'client' => $client) = $this->getJobFactoryAndClient();
        $storageClient = $storageClientFactory->createClientWrapper(
            new ClientOptions(
                null,
                (string) getenv('TEST_STORAGE_API_TOKEN')
            )
        )->getBasicClient();
        $tokenInfo = $storageClient->verifytoken();
        // fabricate an erroneous job which contains unencrypted values
        $id = $storageClient->generateId();
        $job = $jobFactory->loadFromExistingJobData([
            'id' => $id,
            'runId' => $id,
            'componentId' => 'keboola.runner-config-test',
            'projectId' => $tokenInfo['owner']['id'],
            'projectName' => $tokenInfo['owner']['name'],
            'tokenDescription' => $tokenInfo['description'],
            'tokenId' => $tokenInfo['id'],
            '#tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
            'status' => JobFactory::STATUS_CREATED,
            'desiredStatus' => JobFactory::DESIRED_STATUS_PROCESSING,
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
        $kernel = static::createKernel();
        $application = new Application($kernel);

        $command = $application->find('app:run');

        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        putenv('JOB_ID=' . $job->getId());
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        self::assertTrue($testHandler->hasErrorThatContains(
            'Job "' . $job->getId() . '" ended with encryption error: "Internal Server Error occurred."'
        ));
        self::assertTrue($testHandler->hasInfoThatContains('Running job "' . $job->getId() . '".'));
        self::assertEquals(0, $ret);
    }

    public function testExecuteDoubleFailure(): void
    {
        list('factory' => $jobFactory, 'client' => $client) = $this->getJobFactoryAndClient();
        $job = $jobFactory->createNewJob([
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
        $job = $client->createJob($job);
        $job = $jobFactory->modifyJob($job, ['status' => JobFactory::STATUS_ERROR]);
        $client->updateJob($job);
        $kernel = static::createKernel();
        $application = new Application($kernel);

        $command = $application->find('app:run');

        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        putenv('JOB_ID=' . $job->getId());
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        self::assertTrue($testHandler->hasInfoThatContains('Running job "' . $job->getId() . '".'));
        self::assertTrue(
            $testHandler->hasCriticalThatContains('Job "' . $job->getId() . '" ended with application error: "')
        );
        self::assertTrue(
            $testHandler->hasErrorThatContains('Failed to save result for job "' . $job->getId() . '". Error: "')
        );
        self::assertFalse($testHandler->hasInfoThatContains('Job "' . $job->getId() . '" execution finished.'));
        self::assertFalse($testHandler->hasInfoThatContains('Job is already running'));
        self::assertEquals(0, $ret);
    }

    public function testExecuteSkip(): void
    {
        list('factory' => $jobFactory, 'client' => $client) = $this->getJobFactoryAndClient();
        $job = $jobFactory->createNewJob([
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
        $job = $client->createJob($job);
        // set the job to processing, the job will succeed but do nothing
        $job = $jobFactory->modifyJob($job, ['status' => JobFactory::STATUS_PROCESSING]);
        $client->updateJob($job);
        $kernel = static::createKernel();
        $application = new Application($kernel);
        $command = $application->find('app:run');

        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        putenv('JOB_ID=' . $job->getId());
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute(
            ['command' => $command->getName()],
            ['verbosity' => OutputInterface::VERBOSITY_DEBUG,
            'capture_stderr_separately' => true]
        );

        self::assertTrue($testHandler->hasInfoThatContains('Running job "' . $job->getId() . '".'));
        self::assertTrue($testHandler->hasInfoThatContains('Job "' . $job->getId() . '" is already running'));
        self::assertEquals(0, $ret);
    }

    public function testExecuteCustomBackendConfig(): void
    {
        list('factory' => $jobFactory, 'client' => $client) = $this->getJobFactoryAndClient();
        $job = $jobFactory->createNewJob([
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
        $kernel = static::createKernel();
        $application = new Application($kernel);

        $command = $application->find('app:run');

        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        putenv('JOB_ID=' . $job->getId());
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
        $storageClient = new StorageClient([
            'url' => getenv('STORAGE_API_URL'),
            'token' => getenv('TEST_STORAGE_API_TOKEN'),
        ]);
        $tokenInfo = $storageClient->verifyToken();
        $tokenInfo['owner']['features'] = 'pay-as-you-go';

        $creditsCheckerMock = self::createMock(CreditsChecker::class);
        $creditsCheckerMock->method('hasCredits')->willReturn(false);

        $storageClientMock = self::getMockBuilder(StorageClient::class)
            ->setConstructorArgs([[
                'url' => getenv('STORAGE_API_URL'),
                'token' => getenv('TEST_STORAGE_API_TOKEN'),
            ]])
            ->onlyMethods(['verifyToken'])
            ->getMock();
        $storageClientMock->method('verifyToken')->willReturn($tokenInfo);
        $creditsCheckerFactoryMock = self::createMock(CreditsCheckerFactory::class);
        $creditsCheckerFactoryMock->method('getCreditsChecker')->willReturn($creditsCheckerMock);
        $clientWrapperMock = $this->createMock(ClientWrapper::class);
        $clientWrapperMock->method('getBasicClient')->willReturn($storageClientMock);
        $clientWrapperMock->method('getBranchClientIfAvailable')->willReturn($storageClientMock);
        $storageClientFactoryMock = $this->createMock(StorageClientPlainFactory::class);
        $storageClientFactoryMock->method('createClientWrapper')->willReturn($clientWrapperMock);

        list('factory' => $jobFactory, 'client' => $client) = $this->getJobFactoryAndClient();
        /** @var Client $client */
        /** @var JobFactory $jobFactory */
        $job = $jobFactory->createNewJob([
            'componentId' => 'keboola.runner-config-test',
            '#tokenString' => getenv('TEST_STORAGE_API_TOKEN'),
            'mode' => 'run',
            'configData' => [],
        ]);
        $job = $client->createJob($job);
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

        putenv('JOB_ID=' . $job->getId());
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
        list('factory' => $jobFactory, 'client' => $client) = $this->getJobFactoryAndClient();

        $storageClient = new StorageClient([
            'url' => getenv('STORAGE_API_URL'),
            'token' => getenv('TEST_STORAGE_API_TOKEN'),
        ]);

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

        /** @var JobFactory $jobFactory */
        $mockQueueClient = self::createMock(Client::class);
        $mockQueueClient
            ->method('getJob')
            ->willReturn($jobFactory->loadFromExistingJobData($jobData));
        $mockQueueClient
            ->method('getJobFactory')
            ->willReturn($jobFactory);
        $mockQueueClient
            ->method('patchJob')
            ->willReturn($jobFactory->loadFromExistingJobData(array_merge($jobData, ['status' => 'processing'])));
        $mockQueueClient
            ->method('postJobResult')
            ->willThrowException(new StateTransitionForbiddenException(
                'Invalid status transition of job "123" from ' .
                '"terminated (desired: terminating)" to "error desired: processing"'
            ));

        $logger = new Logger('job-runner-test');
        $storageClient->setRunId('124');
        $logger->pushHandler(new StorageApiHandler('job-runner-test', $storageClient));
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
            '',
            []
        ));

        $command = $application->find('app:run');

        putenv('JOB_ID=123');
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        self::assertTrue($testHandler->hasNoticeThatContains(
            'Failed to save result for job "123". State transition forbidden:'
        ));
        self::assertEquals(0, $ret);

        $events = $storageClient->listEvents(['runId' => '124']);
        $messages = array_column($events, 'message');

        self::assertNotEmpty($messages);
        self::assertContains('Running job "123".', $messages);
        self::assertNotContains('Failed to save result for job "123". State transition forbidden:', $messages);
    }
}

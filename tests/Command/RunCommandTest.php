<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Keboola\Csv\CsvFile;
use Keboola\JobQueueInternalClient\JobFactory;
use Keboola\StorageApi\Client as StorageClient;
use Keboola\StorageApi\ClientException;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
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

    public function testExecuteSuccess(): void
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
            'configId' => 'dummy',
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
        self::assertContains('Downloaded file in.c-main.someTable.csv', $messages);
        // event from runner
        self::assertContains('Running component keboola.runner-config-test (row 1 of 1)', $messages);
    }

    public function testExecuteVariablesSharedCode(): void
    {
        $storageClientFactory = new JobFactory\StorageClientFactory((string) getenv('STORAGE_API_URL'));
        $componentsApi = new Components($storageClientFactory->getClient(
            (string) getenv('TEST_STORAGE_API_TOKEN')
        ));
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
                        'sharedCode' => '{{code-id}}',
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
                'mode' => 'run',
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
                            'sharedCode' => 'my-shared-code',
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
        $storageClientFactory = new JobFactory\StorageClientFactory((string) getenv('STORAGE_API_URL'));
        list('factory' => $jobFactory, 'client' => $client) = $this->getJobFactoryAndClient();
        $storageClient = $storageClientFactory->getClient((string) getenv('TEST_STORAGE_API_TOKEN'));
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
            'configId' => 'dummy',
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
            $testHandler->hasErrorThatContains('Job "' . $job->getId() . '" ended with application error: "')
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
            'configId' => 'dummy',
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
            'configId' => 'dummy',
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
}

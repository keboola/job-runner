<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\DevBranches;
use Keboola\StorageApi\Options\Components\Configuration;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

class RunnerStoredConfigTest extends BaseFunctionalTest
{
    public function testRun(): void
    {
        $this->createBuckets();
        $this->createTable('in.c-executor-test', 'source');
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.python-transformation');
        $configuration->setName('test-config');
        $configuration->setConfiguration([
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-executor-test.source',
                            'destination' => 'input.csv',
                        ],
                    ],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'result.csv',
                            'destination' => 'out.c-executor-test.output',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'from shutil import copyfile',
                    'copyfile("/data/in/tables/input.csv", "/data/out/tables/result.csv")',
                ],
            ],
        ]);
        $components = new Components($this->getClient());
        $configId = $components->addConfiguration($configuration)['id'];
        $jobData = [
            'componentId' => 'keboola.python-transformation',
            'mode' => 'run',
            'configId' => $configId,
        ];
        $expectedJobResult = [
            'message' => 'Component processing finished.',
            'configVersion' => '1',
            'images' => ['developer-portal-v2/keboola.python-transformation'],
        ];
        $command = $this->getCommand($jobData, null, null, $expectedJobResult);

        $return = $command->run(new StringInput(''), new NullOutput());

        self::assertEquals(0, $return);
        self::assertTrue($this->getClient()->tableExists('out.c-executor-test.output'));
        $csvData = $this->getClient()->getTableDataPreview('out.c-executor-test.output');
        $data = Client::parseCsv($csvData);
        usort($data, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        self::assertEquals(
            [
                [
                    'name' => 'price',
                    'oldValue' => '100',
                    'newValue' => '1000',
                ],
                [
                    'name' => 'size',
                    'oldValue' => 'small',
                    'newValue' => 'big',
                ],
            ],
            $data,
        );
        $components->deleteConfiguration('keboola.python-transformation', $configId);
    }

    public function testRunBranch(): void
    {
        $this->createBuckets();
        $this->createTable('in.c-executor-test', 'source');
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.python-transformation');
        $configuration->setName('test-config');
        $configuration->setConfiguration([
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-executor-test.source',
                            'destination' => 'input.csv',
                        ],
                    ],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'result.csv',
                            'destination' => 'out.c-executor-test.output',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'from shutil import copyfile',
                    'copyfile("/data/in/tables/input.csv", "/data/out/tables/result.csv")',
                ],
            ],
        ]);
        $components = new Components($this->getClient());
        $configId = $components->addConfiguration($configuration)['id'];
        $client = new Client([
            'url' => getenv('STORAGE_API_URL'),
            'token' => getenv('TEST_STORAGE_API_TOKEN_MASTER'),
        ]);
        $branchApi = new DevBranches($client);
        $branchId = $branchApi->createBranch(uniqid('runner-test-branch'))['id'];
        try {
            $jobData = [
                'componentId' => 'keboola.python-transformation',
                'mode' => 'run',
                'configId' => $configId,
                'branchId' => $branchId,
            ];
            $expectedJobResult = [
                'message' => 'Component processing finished.',
                'configVersion' => '1',
                'images' => ['developer-portal-v2/keboola.python-transformation'],
            ];
            $command = $this->getCommand($jobData, null, null, $expectedJobResult);

            $return = $command->run(new StringInput(''), new NullOutput());

            self::assertEquals(0, $return);
            self::assertTrue($this->getClient()->tableExists(sprintf('out.c-%s-executor-test.output', $branchId)));
            $csvData = $this->getClient()->getTableDataPreview(sprintf('out.c-%s-executor-test.output', $branchId));
            $data = Client::parseCsv($csvData);
            usort($data, function ($a, $b) {
                return strcmp($a['name'], $b['name']);
            });
            self::assertEquals(
                [
                    [
                        'name' => 'price',
                        'oldValue' => '100',
                        'newValue' => '1000',
                    ],
                    [
                        'name' => 'size',
                        'oldValue' => 'small',
                        'newValue' => 'big',
                    ],
                ],
                $data,
            );
        } finally {
            $branchApi->deleteBranch($branchId);
            $components->deleteConfiguration('keboola.python-transformation', $configId);
        }
    }
}

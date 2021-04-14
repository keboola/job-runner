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
            'configVersion' => 1,
            'images' => [
                [
                    [
                        'id' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/' .
                            'developer-portal-v2/keboola.python-transformation:1.1.20',
                        'digests' => [
                            '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/' .
                            'keboola.python-transformation@sha256:' .
                            '3b52906b9dc7d74c897414be0f12c45ee2487a9e377910a4680a802ed2986afc',
                            'quay.io/keboola/python-transformation@sha256:' .
                            'ec73abf4be360803a07bca7d8c1defe84e7b1d57a0615f1c5bcc6c7a39af75fb',
                        ],
                    ],
                ],
            ],
        ];
        $command = $this->getCommand($jobData, null, $expectedJobResult);

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
            $data
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
        $branchId = $branchApi->createBranch('runner-test-branch')['id'];
        try {
            $jobData = [
                'componentId' => 'keboola.python-transformation',
                'mode' => 'run',
                'configId' => $configId,
                'branchId' => $branchId,
            ];
            $expectedJobResult = [
                'message' => 'Component processing finished.',
                'configVersion' => 1,
                'images' => [
                    [
                        [
                            'id' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/' .
                                'developer-portal-v2/keboola.python-transformation:1.1.20',
                            'digests' => [
                                '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/' .
                                'keboola.python-transformation@sha256:' .
                                '3b52906b9dc7d74c897414be0f12c45ee2487a9e377910a4680a802ed2986afc',
                                'quay.io/keboola/python-transformation@sha256:' .
                                'ec73abf4be360803a07bca7d8c1defe84e7b1d57a0615f1c5bcc6c7a39af75fb',
                            ],
                        ],
                    ],
                ],
            ];
            $command = $this->getCommand($jobData, null, $expectedJobResult);

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
                $data
            );
        } finally {
            $branchApi->deleteBranch($branchId);
            $components->deleteConfiguration('keboola.python-transformation', $configId);
        }
    }
}

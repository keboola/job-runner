<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Keboola\StorageApi\Client;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

class RunnerStoredConfigMultipleRowsTest extends BaseFunctionalTest
{
    public function testRun(): void
    {
        $this->createBuckets();
        $this->createTable('in.c-executor-test', 'source');

        $configId = $this->createConfigurationRows(false);
        $jobData = [
            'componentId' => 'keboola.python-transformation',
            'mode' => 'run',
            'configId' => $configId,
        ];
        $command = $this->getCommand($jobData);

        $return = $command->run(new StringInput(''), new NullOutput());

        self::assertEquals(0, $return);
        self::assertTrue($this->getClient()->tableExists('out.c-executor-test.output'));
        self::assertTrue($this->getClient()->tableExists('out.c-executor-test.output-2 '));

        $csvData = $this->getClient()->getTableDataPreview(
            'out.c-executor-test.output',
            [
                'limit' => 1000,
            ]
        );
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

        $csvData = $this->getClient()->getTableDataPreview(
            'out.c-executor-test.output-2',
            [
                'limit' => 1000,
            ]
        );
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
        $components = new Components($this->getClient());
        $components->deleteConfiguration('keboola.python-transformation', $configId);
    }

    private function createConfigurationRows(bool $disabled): string
    {
        $components = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setName('job-runner-test');
        $configuration->setComponentId('keboola.python-transformation');
        $configId = $components->addConfiguration($configuration)['id'];
        $configuration->setConfigurationId($configId);
        $row = new ConfigurationRow($configuration);
        $row->setRowId('123');
        $row->setConfiguration(
            [
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
            ]
        );
        $row->setIsDisabled($disabled);
        $components->addConfigurationRow($row);
        $row = new ConfigurationRow($configuration);
        $row->setRowId('234');
        $row->setConfiguration(
            [
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
                                'destination' => 'out.c-executor-test.output-2',
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
            ]
        );
        $row->setIsDisabled($disabled);
        $components->addConfigurationRow($row);
        return $configId;
    }

    public function testRunOneRow(): void
    {
        $this->createBuckets();
        $this->createTable('in.c-executor-test', 'source');
        $configId = $this->createConfigurationRows(false);

        $jobData = [
            'componentId' => 'keboola.python-transformation',
            'mode' => 'run',
            'configId' => $configId,
            'configRowIds' => ['234'],
        ];
        $command = $this->getCommand($jobData);

        $return = $command->run(new StringInput(''), new NullOutput());

        self::assertEquals(0, $return);
        self::assertFalse($this->getClient()->tableExists('out.c-executor-test.output'));
        self::assertTrue($this->getClient()->tableExists('out.c-executor-test.output-2'));

        $csvData = $this->getClient()->getTableDataPreview(
            'out.c-executor-test.output-2',
            [
                'limit' => 1000,
            ]
        );
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
        $components = new Components($this->getClient());
        $components->deleteConfiguration('keboola.python-transformation', $configId);
    }

    public function testRunRowsDisabled(): void
    {
        $configId = $this->createConfigurationRows(true);
        $this->createBuckets();
        $this->createTable('in.c-executor-test', 'source');

        $expectedJobResult = [
            'message' => 'No configurations executed.',
            'configVersion' => null,
            'images' => [],
        ];
        $jobData = [
            'componentId' => 'keboola.python-transformation',
            'mode' => 'run',
            'configId' => $configId,
        ];
        $command = $this->getCommand($jobData, null, $expectedJobResult);

        $return = $command->run(new StringInput(''), new NullOutput());

        self::assertEquals(0, $return);
        self::assertFalse($this->getClient()->tableExists('out.c-executor-test.output'));
        self::assertFalse($this->getClient()->tableExists('out.c-executor-test.output-2'));
    }
}

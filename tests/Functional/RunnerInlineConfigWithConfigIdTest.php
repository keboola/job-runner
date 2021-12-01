<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Keboola\StorageApi\Client;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

class RunnerInlineConfigWithConfigIdTest extends BaseFunctionalTest
{
    public function testRun(): void
    {
        $this->createBuckets();
        $this->createTable('in.c-executor-test', 'source');

        $componentData = [
            'id' => 'keboola.python-transformation',
            'data' => [
                'definition' => [
                    'type' => 'aws-ecr',
                    'uri' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/' .
                        'developer-portal-v2/keboola.python-transformation',
                ],
                'default_bucket' => true,
                'default_bucket_stage' => 'out',
            ],
        ];
        $clientMock = self::getMockBuilder(Client::class)
            ->setConstructorArgs([['token' => getenv('TEST_STORAGE_API_TOKEN'), 'url' => getenv('STORAGE_API_URL')]])
            ->setMethods(['indexAction', 'getServiceUrl'])
            ->getMock();
        $clientMock->expects(self::any())
            ->method('indexAction')
            ->will(self::returnValue(
                [
                    'services' => [
                        [
                            'id' => 'oauth',
                            'url' => getenv('LEGACY_OAUTH_API_URL'),
                        ],
                    ],
                    'components' => [$componentData],
                ]
            ));
        $clientMock->expects(self::any())
            ->method('getServiceUrl')
            ->with('sandboxes')
            ->willReturn('https://sandboxes.someurl');

        $jobData = [
            'componentId' => 'keboola.python-transformation',
            'mode' => 'run',
            'configId' => 'executor-test',
            'configData' => [
                'storage' => [
                    'input' => [
                        'tables' => [
                            [
                                'source' => 'in.c-executor-test.source',
                                'destination' => 'input.csv',
                            ],
                        ],
                    ],
                ],
                'parameters' => [
                    'script' => [
                        'from shutil import copyfile',
                        'import json',
                        'copyfile("/data/in/tables/input.csv", "/data/out/tables/result.csv")',
                        'with open("/data/out/tables/result.csv.manifest", "w") as out_file:',
                        '   json.dump({"destination": "result"}, out_file)',
                    ],
                ],
            ],
        ];
        /** @var Client $clientMock */
        $command = $this->getCommand($jobData, $clientMock);
        $return = $command->run(new StringInput(''), new NullOutput());
        self::assertEquals(0, $return);
        self::assertTrue($this->getClient()->tableExists('out.c-keboola-python-transformation-executor-test.result'));
        $csvData = $this->getClient()->getTableDataPreview('out.c-keboola-python-transformation-executor-test.result');
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
    }
}

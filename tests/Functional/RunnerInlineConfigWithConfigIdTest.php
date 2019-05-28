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
            ->setConstructorArgs([['token' => getenv('KBC_TEST_TOKEN'), 'url' => getenv('KBC_TEST_URL')]])
            ->setMethods(['indexAction'])
            ->getMock();
        $clientMock->expects(self::any())
            ->method('indexAction')
            ->will(self::returnValue(
                [
                    'services' => [
                        [
                            'id' => 'oauth',
                            'url' => getenv('legacy_oauth_api_url'),
                        ],
                    ],
                    'components' => [$componentData],
                ]
            ));

        $jobId = $this->getClient()->generateId();
        $jobData = [
            'id' => $jobId,
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'config' => 'executor-test',
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
                            'copyfile("/data/in/tables/input.csv", "/data/out/tables/result.csv")',
                        ],
                    ],
                ],
            ],
            'status' => 'waiting',
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

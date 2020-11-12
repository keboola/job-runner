<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Keboola\JobQueueInternalClient\ClientException;
use Keboola\OAuthV2Api\Credentials;
use Keboola\ObjectEncryptor\Legacy\Wrapper\ComponentProjectWrapper;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Options\FileUploadOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

class RunnerInlineConfigTest extends BaseFunctionalTest
{
    public function testRun(): void
    {
        $this->createBuckets();
        $this->createTable('in.c-executor-test', 'source');

        $jobData = [
            'componentId' => 'keboola.python-transformation',
            'mode' => 'run',
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
            ],
        ];
        $command = $this->getCommand($jobData);

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
        self::assertFalse($this->getTestHandler()->hasWarningThatContains('Overriding component tag'));
    }

    public function testRunInvalidRowId(): void
    {
        $jobId = $this->getClient()->generateId();
        $jobData = [
            'id' => $jobId,
            'componentId' => 'keboola.python-transformation',
            'mode' => 'run',
            'configData' => [
                'storage' => [],
                'parameters' => [],
            ],
            'configRowId' => [1, 2, 3],
            'status' => 'waiting',
        ];

        self::expectException(ClientException::class);
        self::expectExceptionMessage('Invalid type for path "job.configRowId');
        $this->getCommand($jobData);
    }

    public function testRunOAuthSecured(): void
    {
        self::markTestSkipped('oauth');
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
            ->setMethods(['indexAction'])
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

        $jobId = $this->getClient()->generateId();
        $jobData = [
            'id' => $jobId,
            'component' => 'keboola.python-transformation',
            'mode' => 'run',
            'configData' => [
                'storage' => [],
                'parameters' => [
                    'script' => [
                        'from pathlib import Path',
                        'import sys',
                        'contents = Path("/data/config.json").read_text()',
                        'print(contents, file=sys.stderr)',
                    ],
                ],
                'authorization' => [
                    'oauth_api' => [
                        'id' => '12345',
                    ],
                ],
            ],
            'status' => 'waiting',
        ];

        $credentials = [
            '#first' => 'superDummySecret',
            'third' => 'fourth',
            'fifth' => [
                '#sixth' => 'anotherTopSecret',
            ],
        ];
        $credentialsEncrypted = $this->getEncryptorFactory()->getEncryptor()->encrypt(
            $credentials,
            ComponentProjectWrapper::class
        );

        $oauthStub = self::getMockBuilder(Credentials::class)
            ->setMethods(['getDetail'])
            ->disableOriginalConstructor()
            ->getMock();
        $oauthStub->method('getDetail')->willReturn($credentialsEncrypted);
        // todo Hosi nevim, tady je konec nas

        /** @var Client $clientMock */
        $command = $this->getCommand($jobData, $clientMock);
        $return = $command->run(new StringInput(''), new NullOutput());

        self::assertEquals(0, $return);
        $output = '';
        foreach ($this->getTestHandler()->getRecords() as $record) {
            if ($record['level'] === Logger::ERROR) {
                $output .= $record['message'];
            }
        }
        $expectedConfig = [
            'parameters' => [], //$data['params']['configData']['parameters'],
            'authorization' => [
                'oauth_api' => [
                    'credentials' => [
                        '#first' => '[hidden]',
                        'third' => 'fourth',
                        'fifth' => [
                            '#sixth' => '[hidden]',
                        ],
                    ],
                    'version' => 2,
                ],
            ],
            'image_parameters' => [],
            'action' => 'run',
            'storage' => [],
        ];
        $expectedConfigRaw = $expectedConfig;
        $expectedConfigRaw['authorization']['oauth_api']['credentials']['#first'] = 'topSecret';
        $expectedConfigRaw['authorization']['oauth_api']['credentials']['fifth']['#sixth'] = 'topSecret';
        self::assertEquals($expectedConfig, json_decode($output, true));
    }

    public function testRunOAuthObfuscated(): void
    {
        self::markTestSkipped('oauth');
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
            ->setMethods(['indexAction'])
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

        $jobId = $this->getClient()->generateId();
        $jobData = [
            'id' => $jobId,
            'component' => 'keboola.python-transformation',
            'mode' => 'run',
            'configData' => [
                'storage' => [],
                'parameters' => [
                    'script' => [
                        'from pathlib import Path',
                        'import sys',
                        'import base64',
                        // [::-1] reverses string, because substr(base64(str)) may be equal to base64(substr(str)
                        'contents = Path("/data/config.json").read_text()[::-1]',
                        'print(base64.standard_b64encode(contents.encode("utf-8")).' .
                            'decode("utf-8"), file=sys.stderr)',
                    ],
                ],
                'authorization' => [
                    'oauth_api' => [
                        'id' => '12345',
                    ],
                ],
            ],
            'status' => 'waiting',
        ];

        $credentials = [
            '#first' => 'superDummySecret',
            'third' => 'fourth',
            'fifth' => [
                '#sixth' => 'anotherTopSecret',
            ],
        ];
        $credentialsEncrypted = $this->getEncryptorFactory()->getEncryptor()->encrypt(
            $credentials,
            ComponentProjectWrapper::class
        );

        $oauthStub = self::getMockBuilder(Credentials::class)
            ->setMethods(['getDetail'])
            ->disableOriginalConstructor()
            ->getMock();
        $oauthStub->method('getDetail')->willReturn($credentialsEncrypted);
        // todo Hosi nevim, tady je konec nas

        /** @var Client $clientMock */
        $command = $this->getCommand($jobData, $clientMock);
        $return = $command->run(new StringInput(''), new NullOutput());

        self::assertEquals(0, $return);
        $output = '';
        foreach ($this->getTestHandler()->getRecords() as $record) {
            if ($record['level'] === Logger::ERROR) {
                $output .= $record['message'];
            }
        }
        $expectedConfig = [
            'parameters' => $jobData['configData']['parameters'],
            'authorization' => [
                'oauth_api' => [
                    'credentials' => [
                        '#first' => 'superDummySecret',
                        'third' => 'fourth',
                        'fifth' => [
                            '#sixth' => 'anotherTopSecret',
                        ],
                    ],
                    'version' => 2,
                ],
            ],
            'image_parameters' => [],
            'action' => 'run',
            'storage' => [],
        ];
        self::assertEquals($expectedConfig, json_decode(strrev((string) base64_decode($output)), true));
    }

    public function testRunTag(): void
    {
        $this->createBuckets();
        $this->createTable('in.c-executor-test', 'source');

        $jobData = [
            'componentId' => 'keboola.python-transformation',
            'mode' => 'run',
            'tag' => '1.1.12',
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
            ],
        ];
        $command = $this->getCommand($jobData);

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
        self::assertTrue($this->getTestHandler()->hasWarning('Overriding component tag with: "1.1.12"'));
    }

    public function testIncrementalTags(): void
    {
        $this->clearFiles();
        // Create file
        $root = $this->getTemp()->getTmpFolder();
        file_put_contents($root . '/upload', 'test');

        $id1 = $this->getClient()->uploadFile(
            $root . '/upload',
            (new FileUploadOptions())->setTags(['executor-test', 'toprocess'])
        );
        $id2 = $this->getClient()->uploadFile(
            $root . '/upload',
            (new FileUploadOptions())->setTags(['executor-test', 'toprocess'])
        );
        $id3 = $this->getClient()->uploadFile(
            $root . '/upload',
            (new FileUploadOptions())->setTags(['executor-test', 'incremental-test'])
        );

        $jobData = [
            'componentId' => 'keboola.python-transformation',
            'mode' => 'run',
            'tag' => '1.1.12',
            'configData' => [
                'storage' => [
                    'input' => [
                        'files' => [
                            [
                                'query' => 'tags: toprocess AND NOT tags: downloaded',
                                'processed_tags' => [
                                    'downloaded',
                                    'experimental',
                                ],
                            ],
                        ],
                    ],
                ],
                'parameters' => [
                    'script' => [
                        'from shutil import copyfile',
                        'import ntpath',
                        'import json',
                        'for filename in os.listdir("/data/in/files/"):',
                        '   if not filename.endswith(".manifest"):',
                        '       print("ntp" + filename)',
                        '       copyfile("/data/in/files/" + filename, "/data/out/files/" + filename)',
                        '       with open("/data/out/files/" + filename + ".manifest", "w") as outfile:',
                        '           data = {"tags": ["executor-test", "processed"]}',
                        '           json.dump(data, outfile)',
                    ],
                ],
            ],
        ];
        $command = $this->getCommand($jobData);
        $return = $command->run(new StringInput(''), new NullOutput());
        self::assertEquals(0, $return);
        sleep(2);
        $listFileOptions = new ListFilesOptions();
        $listFileOptions->setTags(['downloaded']);
        $files = $this->getClient()->listFiles($listFileOptions);
        $ids = [];
        foreach ($files as $file) {
            $ids[] = $file['id'];
        }
        self::assertContains($id1, $ids);
        self::assertContains($id2, $ids);
        self::assertNotContains($id3, $ids);

        $listFileOptions = new ListFilesOptions();
        $listFileOptions->setTags(['processed']);
        $files = $this->getClient()->listFiles($listFileOptions);
        self::assertEquals(2, count($files));
    }
}

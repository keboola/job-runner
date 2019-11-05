<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Aws\S3\S3Client;
use Keboola\Csv\CsvFile;
use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Keboola\StorageApi\Options\GetFileOptions;
use Keboola\StorageApi\Options\ListFilesOptions;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use ZipArchive;

class DebugModeTest extends BaseFunctionalTest
{
    public function testDebugModeInline(): void
    {
        $this->createBuckets();
        $this->clearFiles();
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . 'upload.csv');
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        for ($i = 0; $i < 4; $i++) {
            $csv->writeRow([$i, $i * 100, '1000']);
        }
        $this->getClient()->createTableAsync('in.c-executor-test', 'source', $csv);

        $jobData = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'debug',
                'configData' => [
                    'storage' => [
                        'input' => [
                            'tables' => [
                                [
                                    'source' => 'in.c-executor-test.source',
                                    'destination' => 'source.csv',
                                ],
                            ],
                        ],
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'destination.csv',
                                    'destination' => 'out.c-executor-test.modified',
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
                            '      writer.writerow({"name": row["name"], "oldValue": row["oldValue"] ' .
                                '+ "ping", "newValue": row["newValue"] + "pong"})',
                        ],
                    ],
                ],
            ],
        ];
        $command = $this->getCommand($jobData);

        $return = $command->run(new StringInput(''), new NullOutput());

        self::assertEquals(0, $return);
        // check that output mapping was not done
        self::assertFalse($this->getClient()->tableExists('out.c-executor-test.modified'));

        sleep(2);
        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['debug']);
        $files = $this->getClient()->listFiles($listOptions);
        self::assertEquals(2, count($files));
        self::assertEquals(0, strcasecmp('stage_output.zip', $files[0]['name']));
        self::assertContains('keboola.python-transformation', $files[0]['tags']);
        // @todo put back
        //self::assertContains('JobId:' . $jobId, $files[0]['tags']);
        self::assertContains('debug', $files[0]['tags']);
        self::assertGreaterThan(1000, $files[0]['sizeBytes']);

        self::assertEquals(0, strcasecmp('stage_0.zip', $files[1]['name']));
        self::assertContains('keboola.python-transformation', $files[1]['tags']);
        self::assertContains(
            '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
            $files[1]['tags']
        );
        // @todo uncomment this
        //self::assertContains('JobId:' . $jobId, $files[1]['tags']);
        self::assertContains('debug', $files[1]['tags']);
        self::assertGreaterThan(1000, $files[1]['sizeBytes']);

        $fileName = $this->downloadFile((string) $files[1]['id']);
        $zipArchive = new ZipArchive();
        $zipArchive->open($fileName);
        $config = $zipArchive->getFromName('config.json');
        $config = \GuzzleHttp\json_decode((string) $config, true);
        self::assertEquals('not-secret', $config['parameters']['plain']);
        self::assertArrayHasKey('script', $config['parameters']);
        $tableData = $zipArchive->getFromName('in/tables/source.csv');
        $lines = explode("\n", trim((string) $tableData));
        sort($lines);
        self::assertEquals(
            [
                '"0","0","1000"',
                '"1","100","1000"',
                '"2","200","1000"',
                '"3","300","1000"',
                '"name","oldValue","newValue"',
            ],
            $lines
        );
        $zipArchive->close();
        unlink($fileName);

        $fileName = $this->downloadFile((string) $files[0]['id']);
        $zipArchive = new ZipArchive();
        $zipArchive->open($fileName);
        $config = $zipArchive->getFromName('config.json');
        $config = \GuzzleHttp\json_decode((string) $config, true);
        self::assertEquals('not-secret', $config['parameters']['plain']);
        self::assertArrayHasKey('script', $config['parameters']);
        $tableData = $zipArchive->getFromName('out/tables/destination.csv');
        $lines = explode("\n", trim((string) $tableData));
        sort($lines);
        self::assertEquals(
            [
                '0,0ping,1000pong',
                '1,100ping,1000pong',
                '2,200ping,1000pong',
                '3,300ping,1000pong',
                'name,oldValue,newValue',
            ],
            $lines
        );
    }

    private function downloadFile(string $fileId): string
    {
        $fileInfo = $this->getClient()->getFile($fileId, (new GetFileOptions())->setFederationToken(true));
        // Initialize S3Client with credentials from Storage API
        $target = $this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . 'downloaded-data.zip';
        $s3Client = new S3Client([
            'version' => '2006-03-01',
            'region' => $fileInfo['region'],
            'retries' => $this->getClient()->getAwsRetries(),
            'credentials' => [
                'key' => $fileInfo['credentials']['AccessKeyId'],
                'secret' => $fileInfo['credentials']['SecretAccessKey'],
                'token' => $fileInfo['credentials']['SessionToken'],
            ],
            'http' => [
                'decode_content' => false,
            ],
        ]);
        $s3Client->getObject(array(
            'Bucket' => $fileInfo['s3Path']['bucket'],
            'Key' => $fileInfo['s3Path']['key'],
            'SaveAs' => $target,
        ));
        return $target;
    }

    public function testDebugModeFailure(): void
    {
        $this->createBuckets();
        $this->clearFiles();
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . 'upload.csv');
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        for ($i = 0; $i < 4; $i++) {
            $csv->writeRow([$i, $i * 100, '1000']);
        }
        $this->getClient()->createTableAsync('in.c-executor-test', 'source', $csv);
        $jobData = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'debug',
                'configData' => [
                    'storage' => [
                        'input' => [
                            'tables' => [
                                [
                                    'source' => 'in.c-executor-test.source',
                                    'destination' => 'source.csv',
                                ],
                            ],
                        ],
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'destination.csv',
                                    'destination' => 'out.c-executor-test.modified',
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'plain' => 'not-secret',
                        'script' => [
                            'import sys',
                            'print("Intentional error", file=sys.stderr)',
                            'exit(1)',
                        ],
                    ],
                ],
            ],
        ];
        $expectedJobResult = ['message' => 'Intentional error'];
        $command = $this->getCommand($jobData, null, $expectedJobResult);

        $return = $command->run(new StringInput(''), new NullOutput());

        self::assertEquals(1, $return);
        // check that output mapping was not done
        self::assertFalse($this->getClient()->tableExists('out.c-executor-test.modified'));
        sleep(2);
        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['debug']);
        $files = $this->getClient()->listFiles($listOptions);
        self::assertEquals(1, count($files));
        self::assertEquals(0, strcasecmp('stage_0.zip', $files[0]['name']));
        self::assertContains('keboola.python-transformation', $files[0]['tags']);
        self::assertContains(
            '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
            $files[0]['tags']
        );
        // @todo uncomment
        //self::assertContains('JobId:' . $jobId, $files[0]['tags']);
        self::assertContains('debug', $files[0]['tags']);
        self::assertGreaterThan(1000, $files[0]['sizeBytes']);
    }

    public function testDebugModeConfiguration(): void
    {
        $this->createBuckets();
        $this->clearFiles();
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . 'upload.csv');
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        for ($i = 0; $i < 100; $i++) {
            $csv->writeRow([$i, '100', '1000']);
        }
        $this->getClient()->createTableAsync('in.c-executor-test', 'source', $csv);

        $configuration = new Configuration();
        $configuration->setComponentId('keboola.python-transformation');
        $configuration->setName('test-config');
        $configuration->setConfiguration([
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-executor-test.source',
                            'destination' => 'source.csv',
                        ],
                    ],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'destination.csv',
                            'destination' => 'out.c-executor-test.modified',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'plain' => 'not-secret',
                '#encrypted' => $this->getEncryptorFactory()->getEncryptor()->encrypt('secret'),
                'script' => [
                    'from pathlib import Path',
                    'import sys',
                    'import base64',
                    // [::-1] reverses string, because substr(base64(str)) may be equal to base64(substr(str)
                    'contents = Path("/data/config.json").read_text()[::-1]',
                    'print(base64.standard_b64encode(contents.encode("utf-8")).decode("utf-8"), file=sys.stderr)',
                    'from shutil import copyfile',
                    'copyfile("/data/in/tables/source.csv", "/data/out/tables/destination.csv")',
                ],
            ],
        ]);
        $components = new Components($this->getClient());
        $configId = $components->addConfiguration($configuration)['id'];

        $jobData = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'debug',
                'config' => $configId,
            ],
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
        // check that output mapping was not done
        self::assertFalse($this->getClient()->tableExists('out.c-executor-test.modified'));

        // check that the component got deciphered values
        $output = '';
        foreach ($this->getTestHandler()->getRecords() as $record) {
            if ($record['level'] === 400) {
                $output = $record['message'];
            }
        }
        $config = \GuzzleHttp\json_decode(strrev((string) base64_decode($output)), true);
        self::assertEquals('secret', $config['parameters']['#encrypted']);
        self::assertEquals('not-secret', $config['parameters']['plain']);

        // check that the files were stored
        sleep(2);
        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['debug']);
        $files = $this->getClient()->listFiles($listOptions);
        self::assertEquals(2, count($files));
        self::assertEquals(0, strcasecmp('stage_output.zip', $files[0]['name']));
        self::assertEquals(0, strcasecmp('stage_0.zip', $files[1]['name']));
        self::assertGreaterThan(2000, $files[0]['sizeBytes']);
        self::assertGreaterThan(2000, $files[1]['sizeBytes']);

        // check that the archive does not contain the decrypted value
        $zipFileName = $this->downloadFile((string) $files[1]['id']);
        $zipArchive = new ZipArchive();
        $zipArchive->open($zipFileName);
        $config = $zipArchive->getFromName('config.json');
        $config = \GuzzleHttp\json_decode((string) $config, true);
        self::assertNotEquals('secret', $config['parameters']['#encrypted']);
        self::assertEquals('[hidden]', $config['parameters']['#encrypted']);
        self::assertEquals('not-[hidden]', $config['parameters']['plain']);
        $components->deleteConfiguration('keboola.python-transformation', $configId);
    }

    public function testConfigurationRows(): void
    {
        $this->createBuckets();
        $this->clearFiles();
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . 'upload.csv');
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        for ($i = 0; $i < 100; $i++) {
            $csv->writeRow([$i, '100', '1000']);
        }
        $this->getClient()->createTableAsync('in.c-executor-test', 'source', $csv);

        $components = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.python-transformation');
        $configuration->setName('Test configuration');
        $configId = $components->addConfiguration($configuration)['id'];
        $configuration->setConfigurationId($configId);
        foreach ($this->getConfigurationRows() as $item) {
            $cfgRow = new ConfigurationRow($configuration);
            $cfgRow->setConfiguration($item['configuration']);
            $cfgRow->setRowId($item['id']);
            $cfgRow->setIsDisabled($item['isDisabled']);
            $components->addConfigurationRow($cfgRow);
        }

        $jobData = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'debug',
                'config' => $configId,
            ],
        ];
        $expectedJobResult = [
            'message' => 'Component processing finished.',
            'configVersion' => 3,
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
        // check that output mapping was not done
        self::assertFalse($this->getClient()->tableExists('out.c-executor-test.transposed'));
        sleep(2);
        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['debug']);
        $files = $this->getClient()->listFiles($listOptions);
        self::assertEquals(4, count($files));
        self::assertEquals(0, strcasecmp('stage_output.zip', $files[0]['name']));
        self::assertContains('RowId:row2', $files[0]['tags']);
        self::assertContains('keboola.python-transformation', $files[0]['tags']);
        // @todo uncomment this
        //self::assertContains('JobId:' . $jobId, $files[0]['tags']);
        self::assertContains('debug', $files[0]['tags']);
        self::assertGreaterThan(1500, $files[0]['sizeBytes']);

        self::assertEquals(0, strcasecmp('stage_0.zip', $files[1]['name']));
        self::assertContains('RowId:row2', $files[1]['tags']);
        self::assertContains('keboola.python-transformation', $files[1]['tags']);
        self::assertContains(
            '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
            $files[1]['tags']
        );
        // @todo uncomment this
        //self::assertContains('JobId:' . $jobId, $files[1]['tags']);
        self::assertContains('debug', $files[1]['tags']);
        self::assertGreaterThan(1500, $files[1]['sizeBytes']);

        self::assertEquals(0, strcasecmp('stage_output.zip', $files[2]['name']));
        self::assertContains('RowId:row1', $files[2]['tags']);
        self::assertContains('keboola.python-transformation', $files[2]['tags']);
        // @todo uncomment this
        //self::assertContains('JobId:' . $jobId, $files[2]['tags']);
        self::assertContains('debug', $files[2]['tags']);
        self::assertGreaterThan(1500, $files[2]['sizeBytes']);

        self::assertEquals(0, strcasecmp('stage_0.zip', $files[3]['name']));
        self::assertContains('RowId:row1', $files[3]['tags']);
        self::assertContains('keboola.python-transformation', $files[3]['tags']);
        self::assertContains(
            '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
            $files[3]['tags']
        );
        // @todo uncomment this
        //self::assertContains('JobId:' . $jobId, $files[3]['tags']);
        self::assertContains('debug', $files[3]['tags']);
        self::assertGreaterThan(1500, $files[3]['sizeBytes']);
        $components->deleteConfiguration('keboola.python-transformation', $configId);
    }

    private function getConfigurationRows(): array
    {
        return [
            [
                'id' => 'row1',
                'isDisabled' => false,
                'configuration' => [
                    'storage' => [
                        'input' => [
                            'tables' => [
                                [
                                    'source' => 'in.c-executor-test.source',
                                    'destination' => 'source.csv',
                                ],
                            ],
                        ],
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'destination.csv',
                                    'destination' => 'out.c-executor-test.destination',
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'script' => [
                            'from shutil import copyfile',
                            'copyfile("/data/in/tables/source.csv", "/data/out/tables/destination.csv")',
                        ],
                    ],
                ],
            ],
            [
                'id' => 'row2',
                'isDisabled' => false,
                'configuration' => [
                    'storage' => [
                        'input' => [
                            'tables' => [
                                [
                                    'source' => 'in.c-executor-test.source',
                                    'destination' => 'source.csv',
                                ],
                            ],
                        ],
                        'output' => [
                            'tables' => [
                                [
                                    'source' => 'destination-2.csv',
                                    'destination' => 'out.c-executor-test.destination-2',
                                ],
                            ],
                        ],
                    ],
                    'parameters' => [
                        'script' => [
                            'from shutil import copyfile',
                            'copyfile("/data/in/tables/source.csv", "/data/out/tables/destination-2.csv")',
                        ],
                    ],
                ],
            ],
        ];
    }

    public function testConfigurationRowsProcessors(): void
    {
        $this->createBuckets();
        $this->clearFiles();
        $csv = new CsvFile($this->getTemp()->getTmpFolder() . DIRECTORY_SEPARATOR . 'upload.csv');
        $csv->writeRow(['name', 'oldValue', 'newValue']);
        for ($i = 0; $i < 100; $i++) {
            $csv->writeRow([$i, '100', '1000']);
        }
        $this->getClient()->createTableAsync('in.c-executor-test', 'source', $csv);

        $components = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.python-transformation');
        $configuration->setName('Test configuration');
        $configId = $components->addConfiguration($configuration)['id'];
        $configuration->setConfigurationId($configId);
        $configurationRows = $this->getConfigurationRows();
        $configurationRows[0]['configuration']['processors'] = [
            'after' => [
                [
                    'definition' => [
                        'component' => 'keboola.processor-create-manifest',
                    ],
                    'parameters' => [
                        'columns_from' => 'header',
                    ],
                ],
                [
                    'definition' => [
                        'component' => 'keboola.processor-add-row-number-column',
                    ],
                ],
            ],
        ];
        foreach ($configurationRows as $item) {
            $cfgRow = new ConfigurationRow($configuration);
            $cfgRow->setConfiguration($item['configuration']);
            $cfgRow->setRowId($item['id']);
            $cfgRow->setIsDisabled($item['isDisabled']);
            $components->addConfigurationRow($cfgRow);
        }

        $jobData = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'debug',
                'config' => $configId,
            ],
        ];
        $expectedJobResult = [
            'message' => 'Component processing finished.',
            'configVersion' => 3,
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
                    [
                        'id' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/' .
                            'keboola.processor-create-manifest:0.5.6',
                        'digests' => [
                            '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/' .
                                'keboola.processor-create-manifest@sha256:' .
                                '2f34809009be86dd85809a1ef0605a69582d19613d9142ccf30e74acf3849da6',
                        ],
                    ],
                    [
                        'id' => '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/' .
                            'keboola.processor-add-row-number-column:2.2.1',
                        'digests' => [
                            '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/' .
                                'keboola.processor-add-row-number-column@sha256:' .
                                'c20477b47326ba230879bd7f4b67ba823f3291b91595c0d80a12459b27d1e89d',
                        ],
                    ],
                ],
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
        // check that output mapping was not done
        self::assertFalse($this->getClient()->tableExists('out.c-executor-test.transposed'));

        sleep(2);
        $listOptions = new ListFilesOptions();
        $listOptions->setTags(['debug']);
        $files = $this->getClient()->listFiles($listOptions);
        self::assertEquals(6, count($files));
        self::assertEquals(0, strcasecmp('stage_output.zip', $files[0]['name']));
        self::assertContains('RowId:row2', $files[0]['tags']);
        self::assertContains('keboola.python-transformation', $files[0]['tags']);
        // @todo uncomment this
        //self::assertContains('JobId:' . $jobId, $files[0]['tags']);
        self::assertContains('debug', $files[0]['tags']);
        self::assertGreaterThan(1000, $files[0]['sizeBytes']);

        self::assertEquals(0, strcasecmp('stage_0.zip', $files[1]['name']));
        self::assertContains('RowId:row2', $files[1]['tags']);
        self::assertContains('keboola.python-transformation', $files[1]['tags']);
        self::assertContains(
            '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
            $files[1]['tags']
        );
        // @todo uncomment this
        //self::assertContains('JobId:' . $jobId, $files[1]['tags']);
        self::assertContains('debug', $files[1]['tags']);
        self::assertGreaterThan(1000, $files[1]['sizeBytes']);

        self::assertEquals(0, strcasecmp('stage_output.zip', $files[2]['name']));
        self::assertContains('RowId:row1', $files[2]['tags']);
        self::assertContains('keboola.python-transformation', $files[2]['tags']);
        // @todo uncomment this
        //self::assertContains('JobId:' . $jobId, $files[2]['tags']);
        self::assertContains('debug', $files[2]['tags']);
        self::assertGreaterThan(1000, $files[2]['sizeBytes']);

        self::assertEquals(0, strcasecmp('stage_2.zip', $files[3]['name']));
        self::assertContains('RowId:row1', $files[3]['tags']);
        self::assertContains('keboola.processor-add-row-number-column', $files[3]['tags']);
        self::assertContains(
            '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/' .
            'keboola.processor-add-row-number-column',
            $files[3]['tags']
        );
        // @todo uncomment this
        //self::assertContains('JobId:' . $jobId, $files[3]['tags']);
        self::assertContains('debug', $files[3]['tags']);
        self::assertGreaterThan(1000, $files[3]['sizeBytes']);

        self::assertEquals(0, strcasecmp('stage_1.zip', $files[4]['name']));
        self::assertContains('RowId:row1', $files[4]['tags']);
        self::assertContains('keboola.processor-create-manifest', $files[4]['tags']);
        self::assertContains(
            '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.processor-create-manifest',
            $files[4]['tags']
        );
        // @todo uncomment this
        //self::assertContains('JobId:' . $jobId, $files[4]['tags']);
        self::assertContains('debug', $files[4]['tags']);
        self::assertGreaterThan(1000, $files[4]['sizeBytes']);

        self::assertEquals(0, strcasecmp('stage_0.zip', $files[5]['name']));
        self::assertContains('RowId:row1', $files[5]['tags']);
        self::assertContains('keboola.python-transformation', $files[5]['tags']);
        self::assertContains(
            '147946154733.dkr.ecr.us-east-1.amazonaws.com/developer-portal-v2/keboola.python-transformation',
            $files[5]['tags']
        );
        // @todo uncomment this
        //self::assertContains('JobId:' . $jobId, $files[5]['tags']);
        self::assertContains('debug', $files[5]['tags']);
        self::assertGreaterThan(1000, $files[5]['sizeBytes']);
    }
}

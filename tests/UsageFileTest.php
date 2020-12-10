<?php

declare(strict_types=1);

namespace App\Tests;

use App\UsageFile;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\JobQueueInternalClient\Client;
use Keboola\JobQueueInternalClient\Exception\ClientException;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class UsageFileTest extends TestCase
{
    /**
     * @var Temp
     */
    private $temp;

    /**
     * @var string
     */
    private $dataDir;

    /**
     * @var Filesystem
     */
    private $fs;

    public function setUp(): void
    {
        $this->temp = new Temp('runner-usage-file-test');
        $this->fs = new Filesystem;
        $this->dataDir = $this->temp->getTmpFolder();
        $this->fs->mkdir($this->dataDir . '/out');
    }

    public function testStoreUsageBadInit(): void
    {
        $usageFile = new UsageFile();
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage('Usage file not initialized.');
        $usageFile->storeUsage();
    }

    public function testStoreUsageWrongDataJson(): void
    {
        // there should be "metric" key instead of "random"
        $usage = \GuzzleHttp\json_encode([[
            'random' => 'API calls',
            'value' => 150,
        ]]);
        $this->fs->dumpFile($this->dataDir . '/out/usage.json', $usage);
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var Client $client */
        $usageFile = new UsageFile();
        $usageFile->setDataDir($this->dataDir);
        $usageFile->setFormat('json');
        $usageFile->setQueueClient($client);
        $usageFile->setJobId('1');
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage('Unrecognized option "random" under');
        $usageFile->storeUsage();
    }

    public function testStoreUsageWrongDataYaml(): void
    {
        // there should be "metric" key instead of "random"
        $usage = <<<YAML
- metric: kiloBytes
  value: 987
- random: API Calls
  value: 150
YAML;
        $this->fs->dumpFile($this->dataDir . '/out/usage.yml', $usage);
        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var Client $client */
        $usageFile = new UsageFile();
        $usageFile->setDataDir($this->dataDir);
        $usageFile->setFormat('yaml');
        $usageFile->setQueueClient($client);
        $usageFile->setJobId('1');
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage('Unrecognized option "random" under');
        $usageFile->storeUsage();
    }

    public function testStoreUsageOk(): void
    {
        $usage = \GuzzleHttp\json_encode([[
            'metric' => 'kiloBytes',
            'value' => 150,
        ]]);
        $this->fs->dumpFile($this->dataDir . '/out/usage.json', $usage);

        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->setMethods(['addJobUsage'])
            ->getMock();

        $client->expects(self::once())
            ->method('addJobUsage')
            ->with(
                self::equalTo('1'),
                self::callback(function ($actualUsage) use ($usage) {
                    return $actualUsage === json_decode($usage, true);
                })
            );
        /** @var Client $client */
        $usageFile = new UsageFile();
        $usageFile->setDataDir($this->dataDir);
        $usageFile->setFormat('json');
        $usageFile->setQueueClient($client);
        $usageFile->setJobId('1');
        $usageFile->storeUsage();
    }

    public function testStoreUsageUnknownJob(): void
    {
        $usage = \GuzzleHttp\json_encode([[
            'metric' => 'kiloBytes',
            'value' => 150,
        ]]);
        $this->fs->dumpFile($this->dataDir . '/out/usage.json', $usage);

        $client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->setMethods(['addJobUsage'])
            ->getMock();

        $client->expects(self::once())
            ->method('addJobUsage')
            ->willThrowException(new ClientException('Job "1" not found.'));

        /** @var Client $client */
        $usageFile = new UsageFile();
        $usageFile->setDataDir($this->dataDir);
        $usageFile->setFormat('json');
        $usageFile->setQueueClient($client);
        $usageFile->setJobId('1');
        self::expectException(ApplicationException::class);
        self::expectExceptionMessage('Failed to store Job usage: Job "1" not found.');
        $usageFile->storeUsage();
    }

    public function testDoNotStoreEmptyUsage(): void
    {
        $usage = \GuzzleHttp\json_encode([]);
        $this->fs->dumpFile($this->dataDir . '/out/usage.json', $usage);

        $client = self::getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->setMethods(['addJobUsage'])
            ->getMock();

        $client->expects(self::once())
            ->method('addJobUsage')
            ->with(
                self::equalTo('1'),
                self::callback(function ($actualUsage) {
                    return $actualUsage === [];
                })
            );

        /** @var Client $client */
        $usageFile = new UsageFile();
        $usageFile->setDataDir($this->dataDir);
        $usageFile->setFormat('json');
        $usageFile->setQueueClient($client);
        $usageFile->setJobId('1');
        $usageFile->storeUsage();
    }
}

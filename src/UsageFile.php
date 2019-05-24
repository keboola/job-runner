<?php

declare(strict_types=1);

namespace App;

use Keboola\DockerBundle\Docker\Configuration\Usage\Adapter;
use Keboola\DockerBundle\Docker\Runner\UsageFile\UsageFileInterface;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\JobQueueInternalClient\Client;
use Symfony\Component\Filesystem\Filesystem;


class UsageFile implements UsageFileInterface
{
    /**
     * @var string
     */
    private $dataDir = null;

    /**
     * @var string
     */
    private $format = null;

    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * @var string
     */
    private $jobId = null;

    /** @var Client */
    private $client;

    /** @var Filesystem */
    private $fs;

    public function __construct()
    {
        $this->fs = new Filesystem;
    }

    public function setClient(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Stores usage to ES job
     * @throws ApplicationException
     */
    public function storeUsage(): void
    {
        if ($this->dataDir === null || $this->format === null || $this->jobId === null) {
            throw new ApplicationException('Usage file not initialized.');
        }
        $usageFileName = $this->dataDir . '/out/usage' . $this->adapter->getFileExtension();
        var_dump($usageFileName);
        if ($this->fs->exists($usageFileName)) {
            $usage = $this->adapter->readFromFile($usageFileName);
            // Todo simplify this
            $job = $this->client->getFakeJobData([$this->jobId]);
            if ($job !== null) {
                $currentUsage = $this->client->getJobUsage($this->jobId);
                foreach ($usage as $usageItem) {
                    $currentUsage[] = $usageItem;
                }
                if ($currentUsage) {
                    $this->client->setJobUsage($this->jobId, $currentUsage);
                }
            } else {
                throw new ApplicationException('Job not found', null, ['jobId' => $this->jobId]);
            }
        }
    }

    // phpcs:disable
    public function setDataDir($dataDir): void
    {
        $this->dataDir = $dataDir;
    }
    // phpcs:enable

    public function setFormat(string $format): void
    {
        $this->format = $format;
        $this->adapter = new Adapter($format);
    }

    public function setJobId(string $jobId): void
    {
        $this->jobId = $jobId;
    }
}

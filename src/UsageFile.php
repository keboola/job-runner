<?php

declare(strict_types=1);

namespace App;

use Keboola\DockerBundle\Docker\Configuration\Usage\Adapter;
use Keboola\DockerBundle\Docker\Runner\UsageFile\UsageFileInterface;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\JobQueueInternalClient\Client;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

class UsageFile implements UsageFileInterface
{
    private ?string $dataDir = null;
    private ?string $format = null;
    private Adapter $adapter;
    private ?string $jobId = null;
    private Client $queueClient;

    public function __construct()
    {
    }

    public function setQueueClient(Client $queueClient): void
    {
        $this->queueClient = $queueClient;
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
        $fs = new Filesystem();
        if ($fs->exists($usageFileName)) {
            try {
                $usage = $this->adapter->readFromFile($usageFileName);
                $this->queueClient->addJobUsage($this->jobId, (array) $usage);
            } catch (Throwable $e) {
                throw new ApplicationException('Failed to store Job usage: ' . $e->getMessage(), $e);
            }
        }
    }

    // phpcs:disable
    public function setDataDir(string $dataDir): void
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

<?php

declare(strict_types=1);

namespace App;

use Keboola\DockerBundle\Docker\Configuration\Usage\Adapter;
use Keboola\DockerBundle\Docker\Runner\UsageFile\UsageFileInterface;
use Keboola\DockerBundle\Exception\ApplicationException;

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

    public function __construct()
    {
        //$this->fs = new Filesystem;
    }

    /**
     * Stores usage to ES job
     */
    public function storeUsage(): void
    {
        if ($this->dataDir === null || $this->format === null || $this->jobId === null) {
            throw new ApplicationException('Usage file not initialized.');
        }
        $usageFileName = $this->dataDir . '/out/usage' . $this->adapter->getFileExtension();
        var_dump($usageFileName);
        /*
        if ($this->fs->exists($usageFileName)) {
            $usage = $this->adapter->readFromFile($usageFileName);
            $job = $this->jobMapper->get($this->jobId);
            if ($job !== null) {
                $currentUsage = $job->getUsage();
                foreach ($usage as $usageItem) {
                    $currentUsage[] = $usageItem;
                }
                if ($currentUsage) {
                    $job = $job->setUsage($currentUsage);
                    $this->jobMapper->update($job);
                }
            } else {
                throw new ApplicationException('Job not found', null, ['jobId' => $this->jobId]);
            }
        }
        */
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

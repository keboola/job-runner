<?php

declare(strict_types=1);

namespace App\DockerBundle;

use Keboola\DockerBundle\Docker\Container\Process;
use Keboola\DockerBundle\Docker\Helper\Logger as LoggerHelper;
use Keboola\DockerBundle\Docker\Image;
use Keboola\DockerBundle\Docker\OutputFilter\OutputFilterInterface;
use Keboola\DockerBundle\Docker\RunCommandOptions;
use Keboola\DockerBundle\Docker\Runner\Limits;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\DockerBundle\Exception\OutOfMemoryException;
use Keboola\DockerBundle\Exception\UserException;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\Gelf\ServerFactory;
use Monolog\Logger;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

class Container
{
    /**
     * Container ID
     *
     * @var string
     */
    protected $id;

    /**
     * @var Image
     */
    protected $image;

    /**
     * @var string
     */
    protected $dataDir;

    /**
     * @var string
     */
    protected $tmpDir;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var int Docker CLI process timeout
     */
    protected $dockerCliTimeout = 120;

    /**
     * @var ContainerLogger
     */
    private $containerLogger;

    /**
     * @var string
     */
    private $commandToGetHostIp;

    /**
     * @var RunCommandOptions
     */
    private $runCommandOptions;

    /**
     * @var int
     */
    private $minLogPort;

    /**
     * @var int
     */
    private $maxLogPort;

    /**
     * @var OutputFilterInterface
     */
    private $outputFilter;

    /**
     * @var Limits
     */
    private $limits;

    /**
     * @var string
     */
    private $lastError;

    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return Image
     */
    public function getImage()
    {
        return $this->image;
    }

    /**
     * @param $containerId
     * @param Image $image
     * @param Logger $logger
     * @param ContainerLogger $containerLogger
     * @param string $dataDirectory
     * @param string $tmpDirectory
     * @param string $commandToGetHostIp
     * @param int $minLogPort
     * @param $maxLogPort
     * @param RunCommandOptions $runCommandOptions
     * @param OutputFilterInterface $outputFilter
     * @param Limits $limits
     */
    public function __construct(
        $containerId,
        Image $image,
        Logger $logger,
        ContainerLogger $containerLogger,
        $dataDirectory,
        $tmpDirectory,
        $commandToGetHostIp,
        $minLogPort,
        $maxLogPort,
        RunCommandOptions $runCommandOptions,
        OutputFilterInterface $outputFilter,
        Limits $limits,
    ) {
        $this->logger = $logger;
        $this->containerLogger = $containerLogger;
        $this->image = $image;
        $this->dataDir = $dataDirectory;
        $this->tmpDir = $tmpDirectory;
        $this->id = $containerId;
        $this->commandToGetHostIp = $commandToGetHostIp;
        $this->minLogPort = $minLogPort;
        $this->maxLogPort = $maxLogPort;
        $this->runCommandOptions = $runCommandOptions;
        $this->outputFilter = $outputFilter;
        $this->limits = $limits;
    }

    /**
     * @return Process
     * @throws ApplicationException
     */
    #[\DDTrace\Trace]
    public function run()
    {
        // create container
        $startTime = time();
        try {
            $this->logger->notice("Executing docker process {$this->getImage()->getFullImageId()}.");
            if ($this->getImage()->getSourceComponent()->getLoggerType() === 'gelf') {
                $process = $this->runWithLogger($this->id);
            } else {
                $process = Process::fromShellCommandline($this->getRunCommand($this->id));
                $process->setOutputFilter($this->outputFilter);
                $process->setTimeout(null);
                $this->runWithoutLogger($process);
            }
            $this->logger->notice("Docker process {$this->getImage()->getFullImageId()} finished.");

            $this->checkOOM($this->inspectContainer($this->id));
            if (!$process->isSuccessful()) {
                $this->handleContainerFailure($process, $startTime);
            } else {
                if ($process->getErrorOutput()) {
                    $this->containerLogger->error($process->getErrorOutput());
                }
            }
        } finally {
            try {
                $this->removeContainer($this->id);
            } catch (ProcessFailedException|ProcessTimedOutException $e) {
                $this->logger->notice(sprintf(
                    'Cannot remove container %s %s: %s',
                    $this->getImage()->getFullImageId(),
                    $this->id,
                    $e->getMessage(),
                ));
                // continue
            }
        }
        return $process;
    }

    private function runWithoutLogger(Process $process)
    {
        $process->run(function ($type, $buffer) {
            if ($type === SymfonyProcess::OUT) {
                $this->containerLogger->info($buffer);
            }
        });
    }

    private function runWithLogger($containerName)
    {
        $server = ServerFactory::createServer($this->getImage()->getSourceComponent()->getLoggerServerType());
        $containerId = '';
        $process = null;
        $server->start(
            (int) $this->minLogPort,
            (int) $this->maxLogPort,
            function ($port) use (&$process) {
                // get IP address of host from container
                $processIp = Process::fromShellCommandline($this->commandToGetHostIp);
                $processIp->mustRun();
                $hostIp = trim($processIp->getOutput());

                $this->runCommandOptions->setEnvironmentVariables(
                    array_merge(
                        $this->runCommandOptions->getEnvironmentVariables(),
                        ['KBC_LOGGER_ADDR' => $hostIp, 'KBC_LOGGER_PORT' => $port],
                    ),
                );
                $process = Process::fromShellCommandline($this->getRunCommand($this->id));
                $process->setOutputFilter($this->outputFilter);
                $process->setTimeout(null);
                $process->start();
            },
            function (&$terminated) use (&$process) {
                if (!$process->isRunning()) {
                    $terminated = true;
                    if (trim($process->getOutput()) !== '') {
                        $this->containerLogger->info($process->getOutput());
                    }
                    if (trim($process->getErrorOutput()) !== '') {
                        $this->containerLogger->error($process->getErrorOutput());
                    }
                }
            },
            function ($event) use ($containerName, &$containerId) {
                if (!$containerId) {
                    $inspect = $this->inspectContainer($containerName);
                    $containerId = $inspect['Id'];
                }
                // host is shortened containerId
                if (empty($event['host']) ||
                    empty($event['level']) ||
                    empty($event['timestamp']) ||
                    empty($event['short_message'])
                ) {
                    $this->logger->notice('Missing required field from event.', $event);
                    return;
                }
                if (!str_starts_with($containerId, $event['host'])) {
                    $this->logger->notice('Invalid container host ' . $event['host'], $event);
                    return;
                }
                array_walk_recursive($event, function (&$value) {
                    $value = $this->outputFilter->filter((string) $value);
                });
                $this->containerLogger->addRawRecord(
                    (int) $event['level'],
                    (int) $event['timestamp'],
                    (string) $event['short_message'],
                    $event,
                );
                if ($event['level'] <= 4) {
                    $this->lastError = $event['short_message'];
                }
            },
            null,
            function ($event) {
                $this->containerLogger->error('Invalid message: ' . $event);
            },
        );
        return $process;
    }

    /**
     * @param Process $process
     * @param $startTime
     */
    private function handleContainerFailure(Process $process, $startTime)
    {
        $duration = time() - $startTime;
        $errorOutput = $process->getErrorOutput();
        $output = $process->getOutput();

        $message = $errorOutput;
        if (!$message) {
            $message = $output;
        }
        if (!$message) {
            $message = $this->lastError;
        }
        if (!$message) {
            $message = 'No error message.';
        }

        // make the exception message reasonably short
        $message = LoggerHelper::truncateMessage($message);

        // put the whole message to exception data, but make sure not use too much memory
        $data = [
            'output' => mb_substr($output, -1000000),
            'errorOutput' => mb_substr($errorOutput, -1000000),
            'container' => [
                'id' => $this->getId(),
                'image' => $this->getImage()->getPrintableImageId(),
            ],
        ];

        // killed container or docker socket not available (instance termination)
        if (in_array($process->getExitCode(), [137, 125])) {
            $timeout = $this->image->getProcessTimeout();

            // this catches the timeout from `sudo timeout`
            if ($duration >= $timeout) {
                throw new UserException(
                    "Running {$this->getImage()->getPrintableImageId()} container exceeded the timeout of " .
                    $timeout . ' seconds.',
                    null,
                    $data,
                );
            } else {
                throw new OutOfMemoryException(
                    'Component terminated. Possibly due to out of memory error',
                    null,
                    $data,
                );
            }
        } elseif ($process->getExitCode() === 1) {
            // syrup will log the process error output as part of the exception body
            throw new UserException($message, null, $data);
        } else {
            if ($this->getImage()->getSourceComponent()->isApplicationErrorDisabled()) {
                // syrup will log the process error output as part of the exception body
                throw new UserException(
                    sprintf(
                        "%s container '%s' failed: (%s) %s",
                        $this->getImage()->getPrintableImageId(),
                        $this->getId(),
                        $process->getExitCode(),
                        $message,
                    ),
                    null,
                    $data,
                );
            } else {
                // syrup will log the process error output as part of the exception body
                throw new ApplicationException(
                    sprintf(
                        "%s container '%s' failed: (%s) %s",
                        $this->getImage()->getPrintableImageId(),
                        $this->getId(),
                        $process->getExitCode(),
                        $message,
                    ),
                    null,
                    $data,
                );
            }
        }
    }

    public function getRunCommand(string $containerId): string
    {
        setlocale(LC_CTYPE, 'en_US.UTF-8');
        $envs = '';
        foreach ($this->runCommandOptions->getEnvironmentVariables() as $key => $value) {
            $envs .= ' --env "' .
                str_replace('"', '\"', (string) $key) . '=' .
                str_replace('"', '\"', (string) $value). '"';
        }

        $span = \DDTrace\active_span() ?: null;
        foreach ([
            'DD_TRACE_ID' => \DDTrace\trace_id() ?: '',
            'DD_SPAN_ID' => $span?->id ?: '',
            'DD_SAMPLING_PRIORITY' => getenv('DD_SAMPLING_PRIORITY') ?: '1',
            'DD_TRACE_DEBUG' => getenv('DD_TRACE_DEBUG') ?: 'false',
            'DD_AGENT_HOST' => '127.0.0.1',
            'DD_TRACE_SAMPLE_RATE' => '0.15',
            'DD_ENV' => getenv('DD_ENV') ?: 'unknown',
            'DD_SERVICE' => $this->image->getSourceComponent()->getId(),
            'DD_VERSION' => $this->image->getSourceComponent()->getImageTag(),
        ] as $key => $value) {
            $envs .= ' --env "' .
                str_replace('"', '\"', (string) $key) . '=' .
                str_replace('"', '\"', (string) $value). '"';
        }

        // phpcs:ignore Generic.Files.LineLength.MaxExceeded
        $command = "sudo timeout --signal=SIGKILL {$this->image->getProcessTimeout()} docker run";

        $labels = '';
        foreach ($this->runCommandOptions->getLabels() as $label) {
            $labels .= ' --label ' . escapeshellarg((string) $label);
        }

        $command .= ' --volume ' . escapeshellarg($this->dataDir . ':/data')
            . ' --volume ' . escapeshellarg($this->tmpDir . ':/tmp')
            . ' --memory ' . escapeshellarg($this->limits->getMemoryLimit($this->getImage()))
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
            . ($this->getImage()->getSourceComponent()->hasNoSwap() ? ' --memory-swap ' .
                escapeshellarg($this->limits->getMemorySwapLimit($this->getImage())) : '')
            . ' --net ' . escapeshellarg($this->limits->getNetworkLimit($this->getImage()))
            . ' --cpus ' . escapeshellarg((string) $this->limits->getCpuLimit())
            . $envs
            . $labels
            . ' --name ' . escapeshellarg((string) $containerId)
            . (!$this->getImage()->getSourceComponent()->runAsRoot() ? ' --user $(id -u):$(id -g)' : '')
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
            . ($this->getImage()->getSourceComponent()->overrideKeepalive60s() ? ' --sysctl net.ipv4.tcp_keepalive_time=60' : '')
            . ' ' . escapeshellarg($this->getImage()->getFullImageId());
        return $command;
    }

    /**
     * @param string $containerId
     * @return string
     */
    public function getRemoveCommand($containerId)
    {
        setlocale(LC_CTYPE, 'en_US.UTF-8');
        $command = 'sudo docker rm -f ';
        $command .= escapeshellarg((string) $containerId);
        return $command;
    }

    /**
     * @param string $containerId
     * @return string
     */
    public function getInspectCommand($containerId)
    {
        setlocale(LC_CTYPE, 'en_US.UTF-8');
        $command = 'sudo docker inspect ';
        $command .= escapeshellarg((string) $containerId);
        return $command;
    }

    /**
     * @param $containerId
     */
    public function removeContainer($containerId)
    {
        $process = Process::fromShellCommandline($this->getRemoveCommand($containerId));
        $process->setTimeout($this->dockerCliTimeout);
        $process->mustRun();
    }

    /**
     * @param $containerId
     * @return array
     */
    public function inspectContainer($containerId)
    {
        try {
            $process = Process::fromShellCommandline($this->getInspectCommand($containerId));
            $process->setTimeout($this->dockerCliTimeout);
            $process->mustRun();
            $inspect = json_decode($process->getOutput(), true);
            $inspect = array_pop($inspect);
        } catch (ProcessFailedException|ProcessTimedOutException $e) {
            $this->logger->notice(
                "Cannot inspect container {$this->getImage()->getFullImageId()} '{$containerId}' on failure: " .
                $e->getMessage(),
            );
            $inspect = [];
        }
        return $inspect;
    }

    /**
     * @param array $inspect Container inspect data
     * @throws OutOfMemoryException In case OOM was triggered during container run.
     */
    private function checkOOM(array $inspect)
    {
        if (isset($inspect['State']['OOMKilled']) && $inspect['State']['OOMKilled'] === true) {
            $data = [
                'container' => [
                    'id' => $this->getId(),
                ],
            ];
            throw new OutOfMemoryException(
                "Component out of memory (exceeded {$this->limits->getMemoryLimit($this->getImage())})",
                null,
                $data,
            );
        }
    }
}

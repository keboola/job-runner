<?php

declare(strict_types=1);

namespace App;

use Keboola\DockerBundle\Monolog\Handler\StorageApiHandlerInterface;
use Keboola\StorageApi\Client;
use Keboola\StorageApi\Event;
use function Keboola\Utils\sanitizeUtf8;
use Monolog\Handler\AbstractHandler;
use Monolog\Logger;

class StorageApiHandler extends AbstractHandler implements StorageApiHandlerInterface
{
    /** @var string */
    protected $appName;

    /** @var Client */
    protected $storageApiClient;

    /** @var array */
    private $verbosity;

    public function __construct(string $appName, Client $client)
    {
        parent::__construct();
        $this->storageApiClient = $client;
        $this->appName = $appName;
        $this->verbosity[Logger::DEBUG] = self::VERBOSITY_NONE;
        $this->verbosity[Logger::INFO] = self::VERBOSITY_NORMAL;
        $this->verbosity[Logger::NOTICE] = self::VERBOSITY_NORMAL;
        $this->verbosity[Logger::WARNING] = self::VERBOSITY_NORMAL;
        $this->verbosity[Logger::ERROR] = self::VERBOSITY_NORMAL;
        $this->verbosity[Logger::CRITICAL] = self::VERBOSITY_CAMOUFLAGE;
        $this->verbosity[Logger::ALERT] = self::VERBOSITY_CAMOUFLAGE;
        $this->verbosity[Logger::EMERGENCY] = self::VERBOSITY_CAMOUFLAGE;
    }

    /**
     * Get verbosity for each error level.
     * @return array Key is Monolog error level, value is verbosity constant.
     */
    public function getVerbosity(): array
    {
        return $this->verbosity;
    }

    /**
     * Set verbosity for each error level. If a level is not provided, its verbosity will not be changed.
     * @param array $verbosity Key is Monolog error level, value is verbosity constant.
     */
    public function setVerbosity(array $verbosity): void
    {
        foreach ($verbosity as $level => $value) {
            $this->verbosity[$level] = $value;
        }
    }

    /**
     * @inheritdoc
     */
    public function handle(array $record): bool
    {
        if (($this->verbosity[$record['level']] === self::VERBOSITY_NONE) || empty($record['message'])) {
            return false;
        }

        $event = new Event();
        if (!empty($record['component'])) {
            $event->setComponent($record['component']);
        } else {
            $event->setComponent($this->appName);
        }
        $event->setMessage(sanitizeUtf8($record['message']));
        $event->setRunId($this->storageApiClient->getRunId());

        if ($this->verbosity[$record['level']] === self::VERBOSITY_VERBOSE) {
            $results = $record['context'];
        } else {
            $results = [];
        }
        $event->setResults($results);

        if ($this->verbosity[$record['level']] === self::VERBOSITY_CAMOUFLAGE) {
            $event->setMessage('Application error');
            $event->setDescription('Please contact Keboola Support for help.');
        }

        switch ($record['level']) {
            case Logger::ERROR:
                $type = Event::TYPE_ERROR;
                break;
            case Logger::CRITICAL:
            case Logger::EMERGENCY:
            case Logger::ALERT:
                $type = Event::TYPE_ERROR;
                break;
            case Logger::WARNING:
            case Logger::NOTICE:
                $type = Event::TYPE_WARN;
                break;
            case Logger::INFO:
            default:
                $type = Event::TYPE_INFO;
                break;
        }
        $event->setType($type);

        $this->storageApiClient->createEvent($event);
        return false;
    }
}

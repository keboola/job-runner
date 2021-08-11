<?php

declare(strict_types=1);

namespace App;

use Keboola\StorageApi\Client;

class StorageApiFactory
{
    private string $storageApiUrl;

    public function __construct(string $storageApiUrl)
    {
        $this->storageApiUrl = $storageApiUrl;
    }

    public function getClient(array $options): Client
    {
        $options['jobPollRetryDelay'] = self::getStepPollDelayFunction();
        $options['url'] = $this->getUrl();
        return new Client($options);
    }

    public function getUrl(): string
    {
        return $this->storageApiUrl;
    }

    public static function getStepPollDelayFunction(): callable
    {
        return function ($tries) {
            switch ($tries) {
                case ($tries < 15):
                    return 1;
                case ($tries < 30):
                    return 2;
                default:
                    return 5;
            }
        };
    }
}

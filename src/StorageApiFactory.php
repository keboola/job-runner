<?php

declare(strict_types=1);

namespace App;

use Keboola\StorageApi\Client;

class StorageApiFactory
{
    /** @var string */
    private $storageApiUrl;

    public function __construct(string $storageApiUrl)
    {
        $this->storageApiUrl = $storageApiUrl;
    }

    public function getClient(array $options): Client
    {
        return new Client($options);
    }

    public function getUrl(): string
    {
        return $this->storageApiUrl;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests;

use App\StorageApiFactory;
use Keboola\StorageApi\BranchAwareClient;
use Keboola\StorageApi\Client;
use PHPUnit\Framework\TestCase;

class StorageApiFactoryTest extends TestCase
{
    public function testClient(): void
    {
        $factory = new StorageApiFactory((string) getenv('STORAGE_API_URL'));
        $client = $factory->getClient(['token' => 'dummy']);
        self::assertInstanceOf(Client::class, $client);

        $client = $factory->getBranchClient('1234', ['token' => 'dummy']);
        self::assertInstanceOf(BranchAwareClient::class, $client);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests;

use App\CreditsCheckerFactory;
use Keboola\BillingApi\CreditsChecker;
use Keboola\StorageApi\Client;
use PHPUnit\Framework\TestCase;

class CreditsCheckerFactoryTest extends TestCase
{
    public function testClient(): void
    {
        $factory = new CreditsCheckerFactory();
        $client = new Client(
            [
                'url' => 'http://dummy',
                'token' => 'dummy',
            ]
        );
        self::assertInstanceOf(CreditsChecker::class, $factory->getCreditsChecker($client));
    }
}

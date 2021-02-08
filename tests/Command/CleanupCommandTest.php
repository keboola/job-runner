<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CleanupCommandTest extends KernelTestCase
{
    public function testExecuteSuccess(): void
    {
        $kernel = static::createKernel();
        $application = new Application($kernel);
        $command = $application->find('app:cleanup');
        $property = new ReflectionProperty($command, 'logger');
        $property->setAccessible(true);
        /** @var Logger $logger */
        $logger = $property->getValue($command);
        $testHandler = new TestHandler();
        $logger->pushHandler($testHandler);

        putenv('JOB_ID=1234');
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);
        self::assertTrue($testHandler->hasInfoThatContains('Jinkies'));
        self::assertEquals(0, $ret);
    }
}

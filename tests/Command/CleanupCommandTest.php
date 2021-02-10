<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

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

        $commandText = 'docker run -d -e TARGETS=localhost:12345 -e TIMEOUT=30 ' .
            '--label com.keboola.docker-runner.jobId=%s waisbrot/wait';
        $process = Process::fromShellCommandline(sprintf($commandText, 1234));
        $process->setTimeout(600); // to pull the image if necessary
        $process->mustRun();
        $process = Process::fromShellCommandline(sprintf($commandText, 1234));
        $process->mustRun();
        $process = Process::fromShellCommandline(sprintf($commandText, 4321));
        $process->mustRun();
        $process = Process::fromShellCommandline(
            'docker ps --format "{{.ID}}" --filter "label=com.keboola.docker-runner.jobId=1234"'
        );
        $process->mustRun();
        $toRemove = explode("\n", trim($process->getOutput()));
        $process = Process::fromShellCommandline(
            'docker ps --format "{{.ID}}" --filter "label=com.keboola.docker-runner.jobId=4321"'
        );
        $process->mustRun();
        $toKeep = explode("\n", trim($process->getOutput()));

        putenv('JOB_ID=1234');
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);
        self::assertTrue($testHandler->hasInfoThatContains('Terminating containers for job "1234"'));
        self::assertTrue($testHandler->hasInfoThatContains('Terminating container'));
        self::assertTrue($testHandler->hasInfoThatContains('Finished container cleanup for job "1234"'));
        self::assertEquals(0, $ret);
        $process = Process::fromShellCommandline('docker ps --format "{{.ID}}"');
        $process->mustRun();
        $containers = explode("\n", $process->getOutput());
        self::assertContains($toKeep[0], $containers);
        self::assertCount(2, $toRemove);
        foreach ($toRemove as $containerId) {
            self::assertNotContains($containerId, $containers);
        }
        putenv('JOB_ID=');
    }

    public function testExecuteSuccessInvalid(): void
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

        putenv('JOB_ID=<>!$@%#^$');
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        self::assertTrue($testHandler->hasInfoThatContains('Terminating containers for job "<>!$@%#^$".'));
        self::assertTrue($testHandler->hasInfoThatContains('Finished container cleanup for job "<>!$@%#^$".'));
        self::assertEquals(0, $ret);
    }

    public function testExecuteFailure(): void
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

        putenv('JOB_ID=');
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        self::assertTrue($testHandler->hasErrorThatContains(
            'The "JOB_ID" environment variable is missing in cleanup command.'
        ));
        self::assertEquals(0, $ret);
    }
}

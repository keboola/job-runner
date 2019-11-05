<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class RunCommandTest extends KernelTestCase
{
    public function testExecute(): void
    {
        $kernel = static::createKernel();
        $application = new Application($kernel);

        $command = $application->find('app:run');
        $commandTester = new CommandTester($command);
        $ret = $commandTester->execute([
            'command' => $command->getName(),
        ]);

        $output = $commandTester->getDisplay();
        $this->assertEquals(2, $ret);
        $this->assertContains('JOB_ID env variable is missing.', $output);
    }
}

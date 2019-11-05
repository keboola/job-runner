<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RunnerLoggingOutputTest extends BaseFunctionalTest
{

    public function testRun(): void
    {
        $jobData = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'configData' => [
                    'parameters' => [
                        'script' => [
                            'print("Python Hello")',
                        ],
                    ],
                ],
            ],
        ];
        $command = $this->getCommand($jobData);
        $input = new StringInput('');
        $output = new BufferedOutput();
        $return = $command->run($input, $output);
        self::assertEquals(0, $return);
        // output contains nothing
        self::assertEquals('', $output->fetch());
        // storage events contains info,warn,error
        sleep(1); // wait for events to come int
        /*
        // @todo uncomment this
        $storageEvents = $this->getClient()->listEvents(['runId' => $jobId]);
        self::assertTrue($this->hasEvent('info', 'Output mapping done.', $storageEvents));
        self::assertTrue($this->hasEvent('info', 'Python Hello', $storageEvents));
        self::assertFalse($this->hasEvent('info', 'Normalizing working directory permissions', $storageEvents));
        self::assertFalse($this->hasEvent('info', 'Memory limits - component:', $storageEvents));
        */
        // notices are only in internal logs
        self::assertTrue($this->getTestHandler()->hasNoticeThatContains('Normalizing working directory permissions'));
        self::assertTrue($this->getTestHandler()->hasNoticeThatContains('Memory limits - component:'));
    }
}

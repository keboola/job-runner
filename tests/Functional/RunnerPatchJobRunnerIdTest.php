<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

class RunnerPatchJobRunnerIdTest extends BaseFunctionalTest
{
    public function testRun(): void
    {
        $jobData = [
            'componentId' => 'keboola.python-transformation',
            'mode' => 'run',
            'configData' => [
                'parameters' => [
                    'script' => [
                        'print("Python Hello")',
                    ],
                ],
            ],
            '#tokenString' => (string) getenv('TEST_STORAGE_API_TOKEN'),
        ];
        $command = $this->getCommand($jobData);
        $return = $command->run(new StringInput(''), new NullOutput());
        self::assertEquals(0, $return);
    }
}

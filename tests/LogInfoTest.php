<?php

declare(strict_types=1);

namespace App\Tests;

use App\LogInfo;
use PHPUnit\Framework\TestCase;

class LogInfoTest extends TestCase
{
    public function testToArray(): void
    {
        $logInfo = new LogInfo('123456', 'keboola.component.id', '123');
        self::assertEquals(
            [
                'component' => 'keboola.component.id',
                'owner' => [
                    'id' =>'123',
                ],
                'runId' => '123456',
            ],
            $logInfo->toArray()
        );
    }
}

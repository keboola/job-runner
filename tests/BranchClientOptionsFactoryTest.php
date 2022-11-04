<?php

declare(strict_types=1);

namespace App\Tests;

use App\BranchClientOptionsFactory;
use Closure;
use Keboola\JobQueueInternalClient\JobFactory\Backend;
use Keboola\JobQueueInternalClient\JobFactory\Job;
use PHPUnit\Framework\TestCase;

class BranchClientOptionsFactoryTest extends TestCase
{
    public function testCreateFromJob(): void
    {
        $backend = $this->createMock(Backend::class);
        $backend->expects(self::never())
            ->method('isEmpty')
        ;
        $backend->expects(self::once())
            ->method('getType')
            ->willReturn('small')
        ;
        $backend->expects(self::once())
            ->method('getContext')
            ->willReturn('123-transformation')
        ;

        $jobMock = $this->createMock(Job::class);
        $jobMock->expects(self::once())
            ->method('getComponentId')
            ->willReturn('dummy-component')
        ;
        $jobMock->expects(self::once())
            ->method('getBranchId')
            ->willReturn('dummy-branch')
        ;
        $jobMock->expects(self::once())
            ->method('getRunId')
            ->willReturn('124')
        ;
        $jobMock->expects(self::once())
            ->method('getRunId')
            ->willReturn('124')
        ;
        $jobMock->expects(self::exactly(2))
            ->method('getBackend')
            ->willReturn($backend)
        ;

        $options = (new BranchClientOptionsFactory())->createFromJob($jobMock);

        self::assertSame('dummy-component', $options->getUserAgent());
        self::assertSame('dummy-branch', $options->getBranchId());
        self::assertSame('124', $options->getRunId());

        $backendConfiguration = $options->getBackendConfiguration();
        self::assertNotNull($backendConfiguration);
        self::assertEquals('{"context":"123-transformation","size":"small"}', $backendConfiguration->toJson());

        $retryDelay = $options->getJobPollRetryDelay();
        self::assertNotNull($retryDelay);
        self::assertJobPollRetryDelay($retryDelay);
    }

    private static function assertJobPollRetryDelay(Closure $closure): void
    {
        self::assertSame(1, $closure(0));
        self::assertSame(1, $closure(1));
        self::assertSame(1, $closure(14));
        self::assertSame(2, $closure(15));
        self::assertSame(2, $closure(29));
        self::assertSame(5, $closure(30));
        self::assertSame(5, $closure(1000));
    }
}

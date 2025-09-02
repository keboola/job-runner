<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Helper\BuildBranchClientOptionsHelper;
use Closure;
use Keboola\JobQueueInternalClient\JobFactory\Job;
use Keboola\JobQueueInternalClient\JobFactory\Runtime\Backend;
use PHPUnit\Framework\TestCase;

class BuildBranchClientOptionsHelperTest extends TestCase
{
    public function testBuildFromJob(): void
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
            ->method('getId')
            ->willReturn('124')
        ;
        $jobMock->expects(self::never())
            ->method('getRunId')
            ->willReturn('124')
        ;
        $jobMock->expects(self::exactly(2))
            ->method('getBackend')
            ->willReturn($backend)
        ;

        $options = BuildBranchClientOptionsHelper::buildFromJob($jobMock);

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
    public function testUseBranchStorageWithStorageBranchesFeature(): void
    {
        $backend = $this->createMock(Backend::class);
        $backend->method('getType')->willReturn('small');
        $backend->method('getContext')->willReturn('123-transformation');

        $jobMock = $this->createMock(Job::class);
        $jobMock->method('getComponentId')->willReturn('dummy-component');
        $jobMock->method('getBranchId')->willReturn('dummy-branch');
        $jobMock->method('getId')->willReturn('124');
        $jobMock->method('getBackend')->willReturn($backend);
        $jobMock->method('getProjectFeatures')->willReturn(['storage-branches']);

        $options = BuildBranchClientOptionsHelper::buildFromJob($jobMock);

        self::assertTrue($options->useBranchStorage());
    }
}

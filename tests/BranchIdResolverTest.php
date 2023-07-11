<?php

declare(strict_types=1);

namespace App\Tests;

use App\BranchIdResolver;
use Keboola\DockerBundle\Exception\ApplicationException;
use Keboola\StorageApi\Client;
use Keboola\StorageApiBranch\ClientWrapper;
use PHPUnit\Framework\TestCase;

class BranchIdResolverTest extends TestCase
{
    public function testResolveBranchIdFromRegularBranchId(): void
    {
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->expects(self::never())->method(self::anything());

        $branchIdResolver = new BranchIdResolver();
        $result = $branchIdResolver->resolveBranchId($clientWrapper, '123');

        self::assertSame('123', $result);
    }

    public static function provideMainBranchAliases(): iterable
    {
        yield 'null' => [null];
        yield 'default' => ['default'];
    }

    /** @dataProvider provideMainBranchAliases */
    public function testResolveBranchIdFromMainBranchAlias(?string $branchId): void
    {
        $storageClient = $this->createMock(Client::class);
        $storageClient->expects(self::once())
            ->method('apiGet')
            ->with('dev-branches/')
            ->willReturn([
                ['id' => '123', 'isDefault' => false],
                ['id' => '456', 'isDefault' => true],
                ['id' => '789', 'isDefault' => false],
            ])
        ;

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->expects(self::once())
            ->method('getBasicClient')
            ->willReturn($storageClient)
        ;

        $branchIdResolver = new BranchIdResolver();
        $result = $branchIdResolver->resolveBranchId($clientWrapper, $branchId);

        self::assertSame('456', $result);
    }

    public function testResolveBranchIdFromEmptyString(): void
    {
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->expects(self::never())->method(self::anything());

        $branchIdResolver = new BranchIdResolver();

        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('Can\'t resolve branchId for the job.');

        $branchIdResolver->resolveBranchId($clientWrapper, '');
    }

    public function testResolveBranchIdWithNoBranchAvailable(): void
    {
        $storageClient = $this->createMock(Client::class);
        $storageClient->expects(self::once())
            ->method('apiGet')
            ->with('dev-branches/')
            ->willReturn([])
        ;

        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper->expects(self::once())
            ->method('getBasicClient')
            ->willReturn($storageClient)
        ;

        $branchIdResolver = new BranchIdResolver();

        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('Can\'t resolve branchId for the job.');

        $branchIdResolver->resolveBranchId($clientWrapper, null);
    }
}

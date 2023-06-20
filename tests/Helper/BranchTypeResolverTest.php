<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Helper\BranchTypeResolver;
use Generator;
use Keboola\ObjectEncryptor\ObjectEncryptor;
use Keboola\StorageApi\Client;
use Keboola\StorageApiBranch\ClientWrapper;
use PHPUnit\Framework\TestCase;

class BranchTypeResolverTest extends TestCase
{
    /** @dataProvider provideBranchDetails */
    public function testResolveBranchWithBranch(array $branchDetail, string $expectedType): void
    {
        $clientMock = $this->createMock(Client::class);
        $clientMock
            ->expects(self::once())
            ->method('apiGet')
            ->with('dev-branches/123')
            ->willReturn($branchDetail);
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper
            ->expects(self::once())
            ->method('getBasicClient')
            ->willReturn($clientMock);
        $branchId = '123';
        $branchType = BranchTypeResolver::resolveBranchType($branchId, $clientWrapper);
        self::assertSame($expectedType, $branchType);
    }

    public function provideBranchDetails(): Generator
    {
        yield 'branch is default' => [
            'branchDetail' => [
                'id' => 123,
                'isDefault' => true,
            ],
            'expectedType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
        ];
        yield 'branch is not default' => [
            'branchDetail' => [
                'id' => 123,
                'isDefault' => false,
            ],
            'expectedType' => ObjectEncryptor::BRANCH_TYPE_DEV,
        ];
    }

    /** @dataProvider provideNonBranchTypes */
    public function testResolveBranchWithoutBranch(?string $branchId, string $expectedType): void
    {
        $clientWrapper = $this->createMock(ClientWrapper::class);
        $clientWrapper
            ->expects(self::never())
            ->method('getBasicClient');
        $branchType = BranchTypeResolver::resolveBranchType($branchId, $clientWrapper);
        self::assertSame($expectedType, $branchType);
    }

    public function provideNonBranchTypes(): Generator
    {
        yield 'branch is null' => [
            'branchId' => null,
            'expectedType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
        ];
        yield 'branch is default' => [
            'branchId' => 'default',
            'expectedType' => ObjectEncryptor::BRANCH_TYPE_DEFAULT,
        ];
    }
}

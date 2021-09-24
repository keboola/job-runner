<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Helper\TableResultConverter;
use Keboola\InputMapping\Table\Result\Column as ColumnInfo;
use Keboola\InputMapping\Table\Result\TableInfo;
use Keboola\JobQueueInternalClient\Result\InputOutput\Column;
use PHPUnit\Framework\TestCase;

class TableResultConverterTest extends TestCase
{
    public function testConvertTableInfoToTableResult(): void
    {
        $tableInfoMock = self::createMock(TableInfo::class);
        $tableInfoMock->expects(self::once())
            ->method('getId')
            ->willReturn('in.c-myBucket.table')
        ;
        $tableInfoMock->expects(self::once())
            ->method('getName')
            ->willReturn('table')
        ;
        $tableInfoMock->expects(self::once())
            ->method('getDisplayName')
            ->willReturn('My Table')
        ;

        $tableInfoMock->expects(self::once())
            ->method('getColumns')
            ->willReturn([
                new ColumnInfo('id', []),
                new ColumnInfo('name', []),
            ])
        ;

        $table = TableResultConverter::convertTableInfoToTableResult($tableInfoMock);
        self::assertSame('in.c-myBucket.table', $table->getId());
        self::assertSame('table', $table->getName());
        self::assertSame('My Table', $table->getDisplayName());

        self::assertSame(2, $table->getColumns()->count());
        $columnNames = array_map(function (Column $column) {
            return $column->getName();
        }, iterator_to_array($table->getColumns()));

        self::assertSame(['id', 'name'], $columnNames);
    }
}

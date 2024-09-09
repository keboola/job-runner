<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use App\Helper\TableInfoConverter;
use Keboola\InputMapping\Table\Result\TableInfo;
use PHPUnit\Framework\TestCase;

class TableInfoConverterTest extends TestCase
{
    public function testConvertTableInfoToTableResult(): void
    {
        $tableInfo = new TableInfo([
            'id' => 'in.c-main.my-first-table',
            'displayName' => 'My first table',
            'name' => 'my-first-table',
            'lastImportDate' => '2021-02-12T10:36:15+0100',
            'lastChangeDate' => '2021-12-12T10:36:15+0100',
            'columns' => [
                'first',
                'second',
            ],
        ]);

        $tableResult = TableInfoConverter::convertTableInfoToTableResult($tableInfo);

        self::assertSame(
            [
                'id' => 'in.c-main.my-first-table',
                'name' => 'my-first-table',
                'displayName' => 'My first table',
                'columns' => [
                    [
                        'name' => 'first',
                    ],
                    [
                        'name' => 'second',
                    ],
                ],
            ],
            $tableResult->jsonSerialize(),
        );
    }
}

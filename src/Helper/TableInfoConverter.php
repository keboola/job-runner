<?php

declare(strict_types=1);

namespace App\Helper;

use Keboola\InputMapping\Table\Result\Column as ColumnInfo;
use Keboola\InputMapping\Table\Result\TableInfo;
use Keboola\JobQueueInternalClient\Result\InputOutput\Column;
use Keboola\JobQueueInternalClient\Result\InputOutput\ColumnCollection;
use Keboola\JobQueueInternalClient\Result\InputOutput\Table;

class TableInfoConverter
{
    /** @param array<string, int|string|null> $variables */
    public static function convertTableInfoToTableResult(TableInfo $tableInfo, array $variables = []): Table
    {
        $columnCollection = new ColumnCollection();
        foreach ($tableInfo->getColumns() as $columnInfo) {
            /** @var ColumnInfo $columnInfo */
            $columnCollection->addColumn(new Column($columnInfo->getName()));
        }

        return new Table(
            $tableInfo->getId(),
            $tableInfo->getName(),
            (string) $tableInfo->getDisplayName(),
            $columnCollection,
            $variables,
        );
    }
}

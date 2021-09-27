<?php

declare(strict_types=1);

namespace App\Helper;

use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\InputMapping\Table\Result\Column as ColumnInfo;
use Keboola\InputMapping\Table\Result\TableInfo;
use Keboola\InputMapping\Table\Result\TableMetrics;
use Keboola\JobQueueInternalClient\Result\InputOutput\Column;
use Keboola\JobQueueInternalClient\Result\InputOutput\ColumnCollection;
use Keboola\JobQueueInternalClient\Result\InputOutput\Table;
use Keboola\JobQueueInternalClient\Result\InputOutput\TableCollection;
use Keboola\JobQueueInternalClient\Result\JobMetrics;
use Keboola\JobQueueInternalClient\Result\JobResult;

class OutputResultConverter
{
    /**
     * @param Output[] $outputs
     */
    public static function convertOutputsToResult(array $outputs, JobResult $jobResult): void
    {
        if (!$outputs) {
            return;
        }

        $outputTables = new TableCollection();
        $inputTables = new TableCollection();
        foreach ($outputs as $output) {
            $tableQueue = $output->getTableQueue();
            if ($tableQueue) {
                foreach ($tableQueue->getTableResult()->getTables() as $tableInfo) {
                    $outputTables->addTable(self::convertTableInfoToTableResult($tableInfo));
                }
            }

            $inputTableResult = $output->getInputTableResult();
            if ($inputTableResult) {
                foreach ($inputTableResult->getTables() as $tableInfo) {
                    /** @var TableInfo $tableInfo */
                    $inputTables->addTable(self::convertTableInfoToTableResult($tableInfo));
                }
            }
        }
        $jobResult
            ->setConfigVersion((string)$outputs[0]->getConfigVersion())
            ->setImages(self::getImages($outputs))
            ->setOutputTables($outputTables)
            ->setInputTables($inputTables);
    }

    /**
     * @param Output[] $outputs
     */
    public static function convertOutputsToMetrics(array $outputs, JobMetrics $jobMetrics): void
    {
        if (!$outputs) {
            return;
        }

        $sum = 0;
        foreach ($outputs as $output) {
            $inputTableResult = $output->getInputTableResult();
            if ($inputTableResult) {
                if ($inputTableResult->getMetrics()) {
                    foreach ($inputTableResult->getMetrics()->getTableMetrics() as $tableMetric) {
                        /** @var TableMetrics $tableMetric */
                        $sum += $tableMetric->getCompressedBytes();
                    }
                }
            }
        }
        $jobMetrics->setInputTablesBytesSum($sum);
    }

    private static function getImages(array $outputs): array
    {
        return array_map(
            fn (Output $output) => $output->getImages(),
            $outputs
        );
    }

    private static function convertTableInfoToTableResult(TableInfo $tableInfo): Table
    {
        $columnCollection = new ColumnCollection();
        foreach ($tableInfo->getColumns() as $columnInfo) {
            /** @var ColumnInfo $columnInfo */
            $columnCollection->addColumn(new Column($columnInfo->getName()));
        }

        return new Table(
            $tableInfo->getId(),
            $tableInfo->getName(),
            $tableInfo->getDisplayName(),
            $columnCollection
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Helper;

use Keboola\DockerBundle\Docker\Runner\DataLoader\DataLoaderInterface;
use Keboola\DockerBundle\Docker\Runner\Output;
use Keboola\InputMapping\Table\Result\Column as ColumnInfo;
use Keboola\InputMapping\Table\Result\TableInfo;
use Keboola\InputMapping\Table\Result\TableMetrics as InputTableMetrics;
use Keboola\JobQueueInternalClient\JobFactory\Runtime\Backend;
use Keboola\JobQueueInternalClient\Result\InputOutput\Column;
use Keboola\JobQueueInternalClient\Result\InputOutput\ColumnCollection;
use Keboola\JobQueueInternalClient\Result\InputOutput\Table;
use Keboola\JobQueueInternalClient\Result\InputOutput\TableCollection;
use Keboola\JobQueueInternalClient\Result\JobArtifacts;
use Keboola\JobQueueInternalClient\Result\JobMetrics;
use Keboola\JobQueueInternalClient\Result\JobResult;
use Keboola\JobQueueInternalClient\Result\Variable\Variable;
use Keboola\JobQueueInternalClient\Result\Variable\VariableCollection;
use Keboola\OutputMapping\Table\Result\TableMetrics as OutputTableMetrics;

class OutputResultConverter
{
    /**
     * @param Output[] $outputs
     */
    public static function convertOutputsToResult(array $outputs): JobResult
    {
        $jobResult = new JobResult();
        if (count($outputs) === 0) {
            $jobResult->setMessage('No configurations executed.');
            return $jobResult;
        }
        $jobResult->setMessage('Component processing finished.');

        $outputTables = new TableCollection();
        $inputTables = new TableCollection();
        $jobArtifacts = new JobArtifacts();
        $uploadedArtifacts = [];
        $downloadedArtifacts = [];
        $variables = new VariableCollection();
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
                    $inputTables->addTable(self::convertTableInfoToTableResult($tableInfo));
                }
            }
            $uploadedArtifactsOutput = $output->getArtifactsUploaded();
            array_push($uploadedArtifacts, ...$uploadedArtifactsOutput);

            $downloadedArtifactsOutput = $output->getArtifactsDownloaded();
            array_push($downloadedArtifacts, ...$downloadedArtifactsOutput);

            foreach ($output->getInputVariableValues() as $variableName => $variableValue) {
                $variables->addVariable(new Variable((string) $variableName, $variableValue));
            }
        }
        $jobResult
            ->setConfigVersion((string) $outputs[0]->getConfigVersion())
            ->setImages(self::getImages($outputs))
            ->setOutputTables($outputTables)
            ->setInputTables($inputTables)
            ->setArtifacts(
                $jobArtifacts
                    ->setUploaded($uploadedArtifacts)
                    ->setDownloaded($downloadedArtifacts),
            )
            ->setVariables($variables)
        ;
        return $jobResult;
    }

    /**
     * @param Output[] $outputs
     */
    public static function convertOutputsToMetrics(array $outputs, Backend $backend): JobMetrics
    {
        $jobMetrics = new JobMetrics();

        $inputTablesCompressedBytesSum = 0;
        $outputTablesCompressedBytesSum = 0;
        foreach ($outputs as $output) {
            $inputMetrics = $output->getInputTableResult()?->getMetrics();
            if ($inputMetrics) {
                foreach ($inputMetrics->getTableMetrics() as $tableMetric) {
                    /** @var InputTableMetrics $tableMetric */
                    $inputTablesCompressedBytesSum += $tableMetric->getCompressedBytes();
                }
            }

            $outputMetrics = $output->getOutputTableResult()?->getMetrics();
            if ($outputMetrics) {
                foreach ($outputMetrics->getTableMetrics() as $tableMetric) {
                    /** @var OutputTableMetrics $tableMetric */
                    $outputTablesCompressedBytesSum += $tableMetric->getCompressedBytes();
                }
            }

            /** @var ?DataLoaderInterface $dataLoader */
            $dataLoader = $output->getDataLoader();
            if (!$dataLoader) {
                continue;
            }

            $workspaceBackendSize = $dataLoader->getWorkspaceBackendSize();
            if ($workspaceBackendSize) {
                $jobMetrics->setBackendSize($workspaceBackendSize);
            }
        }
        // container size is just passed around, the default is small here
        // https://github.com/keboola/docker-bundle/blob/dc4fcb6e509f3af8cab1431073915f64517bc632/src/Docker/Runner/Limits.php#L80
        // and here
        // https://github.com/keboola/job-queue-daemon/blob/7af7d3853cb81f585e9c4d29a5638ff2ad40107a/src/Cluster/ResourceTransformer.php#L26
        $jobMetrics->setBackendContainerSize($backend->getContainerType() ?? 'small');

        $jobMetrics->setInputTablesBytesSum($inputTablesCompressedBytesSum);
        $jobMetrics->setOutputTablesBytesSum($outputTablesCompressedBytesSum);

        $backendContext = $backend->getContext();
        if ($backendContext) {
            $jobMetrics->setBackendContext($backendContext);
        }

        return $jobMetrics;
    }

    private static function getImages(array $outputs): array
    {
        return array_map(
            fn (Output $output) => $output->getImages(),
            $outputs,
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
            (string) $tableInfo->getDisplayName(),
            $columnCollection,
        );
    }
}

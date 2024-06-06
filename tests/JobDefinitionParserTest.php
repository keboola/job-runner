<?php

declare(strict_types=1);

namespace App\Tests;

use App\JobDefinitionParser;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Exception\UserException;
use PHPUnit\Framework\TestCase;

class JobDefinitionParserTest extends TestCase
{
    private function getComponent(): Component
    {
        return new Component(
            [
                'id' => 'keboola.r-transformation',
                'data' => [
                    'definition' => [
                        'type' => 'dockerhub',
                        'uri' => 'keboola/docker-demo',
                    ],
                ],
            ],
        );
    }

    public function testSimpleConfigData(): void
    {
        $configData = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-docker-test.source',
                            'destination' => 'transpose.csv',
                        ],
                    ],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'transpose.csv',
                            'destination' => 'out.c-docker-test.transposed',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                    'tdata <- t(data[, !(names(data) %in% ("name"))])',
                ],
            ],
        ];

        $expected = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-docker-test.source',
                            'destination' => 'transpose.csv',
                            'columns' => [],
                            'where_values' => [],
                            'where_operator' => 'eq',
                            'column_types' => [],
                            'overwrite' => false,
                            'use_view' => false,
                            'keep_internal_timestamp_column' => true,
                        ],
                    ],
                    'files' => [],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'transpose.csv',
                            'destination' => 'out.c-docker-test.transposed',
                            'incremental' => false,
                            'primary_key' => [],
                            'columns' => [],
                            'delete_where_values' => [],
                            'delete_where_operator' => 'eq',
                            'delimiter' => ',',
                            'enclosure' => '"',
                            'metadata' => [],
                            'column_metadata' => [],
                            'distribution_key' => [],
                            'write_always' => false,
                            'tags' => [],
                            'schema' => [],
                        ],
                    ],
                    'files' => [],
                ],
            ],
            'parameters' => [
                'script' => [
                    0 => 'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                    1 => 'tdata <- t(data[, !(names(data) %in% ("name"))])',
                ],
            ],
            'processors' => [],
            'shared_code_row_ids' => [],
        ];

        $parser = new JobDefinitionParser();
        $jobDefinition = $parser->parseConfigData(
            $this->getComponent(),
            $configData,
            null,
            'default',
        );

        self::assertEquals('keboola.r-transformation', $jobDefinition->getComponentId());
        self::assertEquals($expected, $jobDefinition->getConfiguration());
        self::assertNull($jobDefinition->getConfigId());
        self::assertNull($jobDefinition->getConfigVersion());
        self::assertNull($jobDefinition->getRowId());
        self::assertFalse($jobDefinition->isDisabled());
        self::assertEmpty($jobDefinition->getState());
        self::assertSame('default', $jobDefinition->getBranchType());
    }

    public function testSingleRowConfiguration(): void
    {
        $config = [
            'id' => 'my-config',
            'version' => 1,
            'configuration' => [
                'storage' => [
                    'input' => [
                        'tables' => [
                            [
                                'source' => 'in.c-docker-test.source',
                                'destination' => 'transpose.csv',
                            ],
                        ],
                    ],
                    'output' => [
                        'tables' => [
                            [
                                'source' => 'transpose.csv',
                                'destination' => 'out.c-docker-test.transposed',
                            ],
                        ],
                    ],
                ],
                'parameters' => [
                    'script' => [
                        'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                        'tdata <- t(data[, !(names(data) %in% ("name"))])',
                    ],
                ],
            ],
            'state' => ['key' => 'val'],
            'rows' => [],
        ];

        $expected = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-docker-test.source',
                            'destination' => 'transpose.csv',
                            'columns' => [],
                            'where_values' => [],
                            'where_operator' => 'eq',
                            'column_types' => [],
                            'overwrite' => false,
                            'use_view' => false,
                            'keep_internal_timestamp_column' => true,
                        ],
                    ],
                    'files' => [],
                ],
                'output' =>
                    [
                        'tables' =>
                            [
                                [
                                    'source' => 'transpose.csv',
                                    'destination' => 'out.c-docker-test.transposed',
                                    'incremental' => false,
                                    'primary_key' => [],
                                    'columns' => [],
                                    'delete_where_values' => [],
                                    'delete_where_operator' => 'eq',
                                    'delimiter' => ',',
                                    'enclosure' => '"',
                                    'metadata' => [],
                                    'column_metadata' => [],
                                    'distribution_key' => [],
                                    'write_always' => false,
                                    'tags' => [],
                                    'schema' => [],
                                ],
                            ],
                        'files' => [],
                    ],
            ],
            'parameters' => [
                'script' => [
                    0 => 'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                    1 => 'tdata <- t(data[, !(names(data) %in% ("name"))])',
                ],
            ],
            'processors' => [],
            'shared_code_row_ids' => [],
        ];

        $parser = new JobDefinitionParser();
        $jobDefinitions = $parser->parseConfig($this->getComponent(), $config, 'default');

        self::assertCount(1, $jobDefinitions);

        $jobDefinition = $jobDefinitions[0];
        self::assertEquals('keboola.r-transformation', $jobDefinition->getComponentId());
        self::assertEquals($expected, $jobDefinition->getConfiguration());
        self::assertEquals('my-config', $jobDefinition->getConfigId());
        self::assertEquals(1, $jobDefinition->getConfigVersion());
        self::assertNull($jobDefinition->getRowId());
        self::assertFalse($jobDefinition->isDisabled());
        self::assertEquals($config['state'], $jobDefinition->getState());
        self::assertSame('default', $jobDefinition->getBranchType());
    }

    public function testMultiRowConfiguration(): void
    {
        $config = [
            'id' => 'my-config',
            'version' => 3,
            'configuration' => [
                'parameters' => [
                    'credentials' => [
                        'username' => 'user',
                        '#password' => 'password',
                    ],
                ],
            ],
            'state' => ['key' => 'val'],
            'rows' => [
                [
                    'id' => 'row1',
                    'version' => 2,
                    'isDisabled' => true,
                    'configuration' => [
                        'storage' => [
                            'input' => [
                                'tables' => [
                                    [
                                        'source' => 'in.c-docker-test.source',
                                        'destination' => 'transpose.csv',
                                    ],
                                ],
                            ],
                        ],
                        'parameters' => [
                            'credentials' => [
                                'username' => 'override user',
                            ],
                            'key' => 'val',
                        ],
                    ],
                    'state' => [
                        'key1' => 'val1',
                    ],
                ],
                [
                    'id' => 'row2',
                    'version' => 1,
                    'isDisabled' => false,
                    'configuration' => [
                        'storage' => [
                            'input' => [],
                        ],
                    ],
                    'state' => [
                        'key2' => 'val2',
                    ],
                ],
            ],
        ];

        $expectedRow1 = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-docker-test.source',
                            'destination' => 'transpose.csv',
                            'columns' => [],
                            'where_values' => [],
                            'where_operator' => 'eq',
                            'column_types' => [],
                            'overwrite' => false,
                            'use_view' => false,
                            'keep_internal_timestamp_column' => true,
                        ],
                    ],
                    'files' => [],
                ],
            ],
            'parameters' => [
                'credentials' => [
                    'username' => 'override user',
                    '#password' => 'password',
                ],
                'key' => 'val',
            ],
            'processors' => [],
            'shared_code_row_ids' => [],
        ];

        $expectedRow2 = [
            'storage' => [
                'input' => [
                    'tables' => [],
                    'files' => [],
                ],
            ],
            'parameters' => [
                'credentials' => [
                    'username' => 'user',
                    '#password' => 'password',
                ],
            ],
            'processors' => [],
            'shared_code_row_ids' => [],
        ];

        $parser = new JobDefinitionParser();
        $jobDefinitions = $parser->parseConfig($this->getComponent(), $config, 'dev');

        self::assertCount(2, $jobDefinitions);

        $jobDefinition = $jobDefinitions[0];
        self::assertEquals('keboola.r-transformation', $jobDefinition->getComponentId());
        self::assertEquals($expectedRow1, $jobDefinition->getConfiguration());
        self::assertEquals('my-config', $jobDefinition->getConfigId());
        self::assertEquals(3, $jobDefinition->getConfigVersion());
        self::assertEquals('row1', $jobDefinition->getRowId());
        self::assertTrue($jobDefinition->isDisabled());
        self::assertEquals(['key1' => 'val1'], $jobDefinition->getState());
        self::assertSame('dev', $jobDefinition->getBranchType());

        $jobDefinition = $jobDefinitions[1];
        self::assertEquals('keboola.r-transformation', $jobDefinition->getComponentId());
        self::assertEquals($expectedRow2, $jobDefinition->getConfiguration());
        self::assertEquals('my-config', $jobDefinition->getConfigId());
        self::assertEquals(3, $jobDefinition->getConfigVersion());
        self::assertEquals('row2', $jobDefinition->getRowId());
        self::assertFalse($jobDefinition->isDisabled());
        self::assertEquals(['key2' => 'val2'], $jobDefinition->getState());
        self::assertSame('dev', $jobDefinition->getBranchType());
    }

    public function testSimpleConfigDataWithConfigId(): void
    {
        $configData = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-docker-test.source',
                            'destination' => 'transpose.csv',
                        ],
                    ],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'transpose.csv',
                            'destination' => 'out.c-docker-test.transposed',
                        ],
                    ],
                ],
            ],
            'parameters' => [
                'script' => [
                    'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                    'tdata <- t(data[, !(names(data) %in% ("name"))])',
                ],
            ],
        ];

        $expected = [
            'storage' => [
                'input' => [
                    'tables' => [
                        [
                            'source' => 'in.c-docker-test.source',
                            'destination' => 'transpose.csv',
                            'columns' => [],
                            'where_values' => [],
                            'where_operator' => 'eq',
                            'column_types' => [],
                            'overwrite' => false,
                            'use_view' => false,
                            'keep_internal_timestamp_column' => true,
                        ],
                    ],
                    'files' => [],
                ],
                'output' => [
                    'tables' => [
                        [
                            'source' => 'transpose.csv',
                            'destination' => 'out.c-docker-test.transposed',
                            'incremental' => false,
                            'primary_key' => [],
                            'columns' => [],
                            'delete_where_values' => [],
                            'delete_where_operator' => 'eq',
                            'delimiter' => ',',
                            'enclosure' => '"',
                            'metadata' => [],
                            'column_metadata' => [],
                            'distribution_key' => [],
                            'write_always' => false,
                            'tags' => [],
                            'schema' => [],
                        ],
                    ],
                    'files' => [],
                ],
            ],
            'parameters' => [
                'script' => [
                    0 => 'data <- read.csv(file = "/data/in/tables/transpose.csv");',
                    1 => 'tdata <- t(data[, !(names(data) %in% ("name"))])',
                ],
            ],
            'processors' => [],
            'shared_code_row_ids' => [],
        ];

        $parser = new JobDefinitionParser();
        $jobDefinition = $parser->parseConfigData($this->getComponent(), $configData, '1234', 'dev');

        self::assertEquals('keboola.r-transformation', $jobDefinition->getComponentId());
        self::assertEquals($expected, $jobDefinition->getConfiguration());
        self::assertEquals('1234', $jobDefinition->getConfigId());
        self::assertNull($jobDefinition->getConfigVersion());
        self::assertNull($jobDefinition->getRowId());
        self::assertFalse($jobDefinition->isDisabled());
        self::assertEmpty($jobDefinition->getState());
        self::assertSame('dev', $jobDefinition->getBranchType());
    }

    public function testMultiRowConfigurationWithInvalidProcessors1(): void
    {
        $config = [
            'id' => 'my-config',
            'version' => 3,
            'state' => [],
            'configuration' => [
                'parameters' => ['first' => 'second'],
                'processors' => [
                    'before' => [],
                    'after' => [
                        [
                            'definition' => [
                                'component' => 'keboola.processor-skip-lines',
                            ],
                            'parameters' => [
                                'lines' => 1,
                            ],
                        ],
                    ],
                ],
            ],
            'rows' => [
                [
                    'id' => 'row1',
                    'version' => 1,
                    'isDisabled' => false,
                    'state' => [],
                    'configuration' => [
                        'parameters' => [
                            'a' => 'b',
                        ],
                    ],
                ],
                [
                    'id' => 'row2',
                    'version' => 1,
                    'isDisabled' => false,
                    'state' => [],
                    'configuration' => [
                        'parameters' => [
                            'c' => 'd',
                        ],
                        'processors' => [
                            'before' => [
                                [
                                    'definition' => [
                                        'component' => 'keboola.processor-iconv',
                                    ],
                                    'parameters' => [
                                        'source_encoding' => 'WINDOWS-1250',
                                    ],
                                ],
                            ],
                            'after' => [],
                        ],
                    ],
                ],
            ],
        ];

        $parser = new JobDefinitionParser();
        $this->expectException(UserException::class);
        $this->expectExceptionMessage(
            'Processors may be set either in configuration or in configuration row, but not in both places',
        );
        $parser->parseConfig($this->getComponent(), $config, 'default');
    }

    public function testEmptyConfig(): void
    {
        $config = [
            'id' => 'my-config',
            'version' => 3,
            'state' => null,
            'configuration' => null,
            'rows' => [],
        ];

        $parser = new JobDefinitionParser();
        $jobDefinitions = $parser->parseConfig($this->getComponent(), $config, 'default');

        self::assertCount(1, $jobDefinitions);
        self::assertSame(
            [
                'shared_code_row_ids' => [],
                'storage' => [],
                'processors' => [],
                'parameters' => [],
            ],
            $jobDefinitions[0]->getConfiguration(),
        );
    }

    public function testMultiRowConfigurationWithInvalidProcessors2(): void
    {
        $config = [
            'id' => 'my-config',
            'version' => 3,
            'state' => [],
            'configuration' => [
                'parameters' => ['first' => 'second'],
                'processors' => [
                    'before' => [
                        [
                            'definition' => [
                                'component' => 'keboola.processor-skip-lines',
                            ],
                            'parameters' => [
                                'lines' => 1,
                            ],
                        ],
                    ],
                    'after' => [],
                ],
            ],
            'rows' => [
                [
                    'id' => 'row1',
                    'version' => 1,
                    'isDisabled' => false,
                    'state' => [],
                    'configuration' => [
                        'parameters' => [
                            'a' => 'b',
                        ],
                    ],
                ],
                [
                    'id' => 'row2',
                    'version' => 1,
                    'isDisabled' => false,
                    'state' => [],
                    'configuration' => [
                        'parameters' => [
                            'c' => 'd',
                        ],
                        'processors' => [
                            'before' => [
                                [
                                    'definition' => [
                                        'component' => 'keboola.processor-iconv',
                                    ],
                                    'parameters' => [
                                        'source_encoding' => 'WINDOWS-1250',
                                    ],
                                ],
                            ],
                            'after' => [],
                        ],
                    ],
                ],
            ],
        ];

        $parser = new JobDefinitionParser();
        $this->expectException(UserException::class);
        $this->expectExceptionMessage(
            'Processors may be set either in configuration or in configuration row, but not in both places',
        );
        $parser->parseConfig($this->getComponent(), $config, 'default');
    }

    public function testNullRows(): void
    {
        $config = [
            'id' => 'my-config',
            'version' => 1,
            'configuration' => [
                'storage' => [],
                'parameters' => ['first' => 'second'],
            ],
            'state' => ['key' => 'val'],
            'rows' => null,
        ];

        $expected = [
            'storage' => [],
            'parameters' => ['first' => 'second'],
            'processors' => [],
            'shared_code_row_ids' => [],
        ];

        $parser = new JobDefinitionParser();
        $jobDefinitions = $parser->parseConfig($this->getComponent(), $config, 'default');

        self::assertCount(1, $jobDefinitions);

        $jobDefinition = $jobDefinitions[0];
        self::assertEquals('keboola.r-transformation', $jobDefinition->getComponentId());
        self::assertEquals($expected, $jobDefinition->getConfiguration());
    }

    public function testRequestedRowIdsFilterThrowsException(): void
    {
        $config = [
            'id' => 'my-config',
            'version' => 1,
            'state' => [],
            'configuration' => [],
            'rows' => [
                ['id' => 'row1'],
                ['id' => 'row2'],
            ],
        ];

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('None of rows "non-existing-row-id" was found.');

        (new JobDefinitionParser())->parseConfig(
            component: $this->getComponent(),
            config: $config,
            branchType: 'default',
            rowIds: ['non-existing-row-id'],
        );
    }

    public function testRequestedRowIdsFilterReturnsCorrectJobs(): void
    {
        $config = [
            'id' => 'my-config',
            'version' => 1,
            'state' => [],
            'configuration' => [],
            'rows' => [
                [
                    'id' => 'row1',
                    'configuration' => [
                        'parameters' => [],
                    ],
                    'state' => null,
                    'isDisabled' => false,
                ],
                [
                    'id' => 'row2',
                    'configuration' => [
                        'parameters' => [
                            'test' => '{{non-existing-variable}}',
                        ],
                    ],
                    'state' => null,
                    'isDisabled' => false,
                ],
            ],
        ];

        $jobDefinitions = (new JobDefinitionParser())->parseConfig(
            component: $this->getComponent(),
            config: $config,
            branchType: 'default',
            rowIds: ['row1'],
        );

        self::assertCount(1, $jobDefinitions);
        self::assertEquals('row1', $jobDefinitions[0]->getRowId());
    }
}

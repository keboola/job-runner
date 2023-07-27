<?php

declare(strict_types=1);

namespace App\Tests;

use App\DockerBundleJobDefinitionParser;
use Keboola\DockerBundle\Docker\Component;
use Keboola\DockerBundle\Exception\UserException;
use PHPUnit\Framework\TestCase;

class DockerBundleJobDefinitionParserTest extends TestCase
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
            ]
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

        $parser = new DockerBundleJobDefinitionParser();
        $parser->parseConfigData($this->getComponent(), $configData, null, 'default');

        self::assertCount(1, $parser->getJobDefinitions());
        self::assertEquals('keboola.r-transformation', $parser->getJobDefinitions()[0]->getComponentId());
        self::assertEquals($expected, $parser->getJobDefinitions()[0]->getConfiguration());
        self::assertNull($parser->getJobDefinitions()[0]->getConfigId());
        self::assertNull($parser->getJobDefinitions()[0]->getConfigVersion());
        self::assertNull($parser->getJobDefinitions()[0]->getRowId());
        self::assertFalse($parser->getJobDefinitions()[0]->isDisabled());
        self::assertEmpty($parser->getJobDefinitions()[0]->getState());
        self::assertSame('default', $parser->getJobDefinitions()[0]->getBranchType());
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

        $parser = new DockerBundleJobDefinitionParser();
        $parser->parseConfig($this->getComponent(), $config, 'default');

        self::assertCount(1, $parser->getJobDefinitions());
        self::assertEquals('keboola.r-transformation', $parser->getJobDefinitions()[0]->getComponentId());
        self::assertEquals($expected, $parser->getJobDefinitions()[0]->getConfiguration());
        self::assertEquals('my-config', $parser->getJobDefinitions()[0]->getConfigId());
        self::assertEquals(1, $parser->getJobDefinitions()[0]->getConfigVersion());
        self::assertNull($parser->getJobDefinitions()[0]->getRowId());
        self::assertFalse($parser->getJobDefinitions()[0]->isDisabled());
        self::assertEquals($config['state'], $parser->getJobDefinitions()[0]->getState());
        self::assertSame('default', $parser->getJobDefinitions()[0]->getBranchType());
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

        $parser = new DockerBundleJobDefinitionParser();
        $parser->parseConfig($this->getComponent(), $config, 'dev');

        self::assertCount(2, $parser->getJobDefinitions());
        self::assertEquals('keboola.r-transformation', $parser->getJobDefinitions()[0]->getComponentId());
        self::assertEquals($expectedRow1, $parser->getJobDefinitions()[0]->getConfiguration());
        self::assertEquals('my-config', $parser->getJobDefinitions()[0]->getConfigId());
        self::assertEquals(3, $parser->getJobDefinitions()[0]->getConfigVersion());
        self::assertEquals('row1', $parser->getJobDefinitions()[0]->getRowId());
        self::assertTrue($parser->getJobDefinitions()[0]->isDisabled());
        self::assertEquals(['key1' => 'val1'], $parser->getJobDefinitions()[0]->getState());
        self::assertSame('dev', $parser->getJobDefinitions()[0]->getBranchType());

        self::assertEquals('keboola.r-transformation', $parser->getJobDefinitions()[1]->getComponentId());
        self::assertEquals($expectedRow2, $parser->getJobDefinitions()[1]->getConfiguration());
        self::assertEquals('my-config', $parser->getJobDefinitions()[1]->getConfigId());
        self::assertEquals(3, $parser->getJobDefinitions()[1]->getConfigVersion());
        self::assertEquals('row2', $parser->getJobDefinitions()[1]->getRowId());
        self::assertFalse($parser->getJobDefinitions()[1]->isDisabled());
        self::assertEquals(['key2' => 'val2'], $parser->getJobDefinitions()[1]->getState());
        self::assertSame('dev', $parser->getJobDefinitions()[1]->getBranchType());
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

        $parser = new DockerBundleJobDefinitionParser();
        $parser->parseConfigData($this->getComponent(), $configData, '1234', 'dev');

        self::assertCount(1, $parser->getJobDefinitions());
        self::assertEquals('keboola.r-transformation', $parser->getJobDefinitions()[0]->getComponentId());
        self::assertEquals($expected, $parser->getJobDefinitions()[0]->getConfiguration());
        self::assertEquals('1234', $parser->getJobDefinitions()[0]->getConfigId());
        self::assertNull($parser->getJobDefinitions()[0]->getConfigVersion());
        self::assertNull($parser->getJobDefinitions()[0]->getRowId());
        self::assertFalse($parser->getJobDefinitions()[0]->isDisabled());
        self::assertEmpty($parser->getJobDefinitions()[0]->getState());
        self::assertSame('dev', $parser->getJobDefinitions()[0]->getBranchType());
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

        $parser = new DockerBundleJobDefinitionParser();
        $this->expectException(UserException::class);
        $this->expectExceptionMessage(
            'Processors may be set either in configuration or in configuration row, but not in both places'
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

        $parser = new DockerBundleJobDefinitionParser();
        $parser->parseConfig($this->getComponent(), $config, 'default');
        self::assertSame(
            [
                'shared_code_row_ids' => [],
                'storage' => [],
                'processors' => [],
                'parameters' => [],
            ],
            $parser->getJobDefinitions()[0]->getConfiguration()
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

        $parser = new DockerBundleJobDefinitionParser();
        $this->expectException(UserException::class);
        $this->expectExceptionMessage(
            'Processors may be set either in configuration or in configuration row, but not in both places'
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

        $parser = new DockerBundleJobDefinitionParser();
        $parser->parseConfig($this->getComponent(), $config, 'default');

        self::assertCount(1, $parser->getJobDefinitions());
        self::assertEquals('keboola.r-transformation', $parser->getJobDefinitions()[0]->getComponentId());
        self::assertEquals($expected, $parser->getJobDefinitions()[0]->getConfiguration());
    }
}

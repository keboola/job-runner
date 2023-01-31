<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Keboola\StorageApi\Components;
use Keboola\StorageApi\Options\Components\Configuration;
use Keboola\StorageApi\Options\Components\ConfigurationRow;
use Monolog\Logger;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

class EncryptionTest extends BaseFunctionalTest
{
    public function testStoredConfigDecryptEncryptComponent(): void
    {
        // need to set this before hand so that the encryption wrappers are available
        $tokenInfo = $this->getClient()->verifyToken();
        $configData = [
            'parameters' => [
                'script' => [
                    'from pathlib import Path',
                    'import sys',
                    'import base64',
                    // [::-1] reverses string, because substr(base64(str)) may be equal to base64(substr(str)
                    'contents = Path("/data/config.json").read_text()[::-1]',
                    'print(base64.standard_b64encode(contents.encode("utf-8")).decode("utf-8"), file=sys.stderr)',
                ],
                'key1' => 'first',
                '#key3' => $this->getObjectEncryptor()->encryptForComponent(
                    'third',
                    'keboola.python-transformation',
                ),
                '#key4' => $this->getObjectEncryptor()->encryptForProject(
                    'fourth',
                    'keboola.python-transformation',
                    (string) $tokenInfo['owner']['id']
                ),
            ],
        ];
        $components = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.python-transformation');
        $configuration->setName('Test configuration');
        $configuration->setConfiguration($configData);
        $configId = $components->addConfiguration($configuration)['id'];
        $jobData = [
            'componentId' => 'keboola.python-transformation',
            'mode' => 'run',
            'configId' => $configId,
        ];
        $command = $this->getCommand($jobData);

        $return = $command->run(new StringInput(''), new NullOutput());

        self::assertEquals(0, $return);
        $output = '';
        foreach ($this->getTestHandler()->getRecords() as $record) {
            if ($record['level'] === Logger::ERROR) {
                $output = $record['message'];
            }
        }
        $config = json_decode(strrev((string) base64_decode($output)), true);
        self::assertIsArray($config);
        self::assertIsArray($config['parameters']);
        self::assertEquals('first', $config['parameters']['key1']);
        self::assertEquals('third', $config['parameters']['#key3']);
        self::assertEquals('fourth', $config['parameters']['#key4']);
    }

    public function testStoredConfigRowDecryptEncryptComponent(): void
    {
        // need to set this before hand so that the encryption wrappers are available
        $tokenInfo = $this->getClient()->verifyToken();
        $configData = [
            'parameters' => [
                'script' => [
                    'from pathlib import Path',
                    'import sys',
                    'import base64',
                    // [::-1] reverses string, because substr(base64(str)) may be equal to base64(substr(str))
                    'contents = Path("/data/config.json").read_text()[::-1]',
                    'print(base64.standard_b64encode(contents.encode("utf-8")).decode("utf-8"), file=sys.stderr)',
                ],
                'configKey1' => 'first',
                '#configKey3' => $this->getObjectEncryptor()->encryptForComponent(
                    'third',
                    'keboola.python-transformation',
                ),
                '#configKey4' => $this->getObjectEncryptor()->encryptForProject(
                    'fourth',
                    'keboola.python-transformation',
                    (string) $tokenInfo['owner']['id'],
                ),
            ],
        ];
        $components = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.python-transformation');
        $configuration->setName('Test configuration');
        $configuration->setConfiguration($configData);
        $configId = $components->addConfiguration($configuration)['id'];
        $configuration->setConfigurationId($configId);
        $configRow = new ConfigurationRow($configuration);
        $configRow->setConfiguration([
            'parameters' => [
                'rowKey1' => 'value1',
                '#rowKey3' => $this->getObjectEncryptor()->encryptForComponent(
                    'value3',
                    'keboola.python-transformation',
                ),
                '#rowKey4' => $this->getObjectEncryptor()->encryptForProject(
                    'value4',
                    'keboola.python-transformation',
                    (string) $tokenInfo['owner']['id'],
                ),
            ],
        ]);
        $components->addConfigurationRow($configRow);
        $jobData = [
            'componentId' => 'keboola.python-transformation',
            'mode' => 'run',
            'configId' => $configId,
        ];
        $command = $this->getCommand($jobData);

        $return = $command->run(new StringInput(''), new NullOutput());

        self::assertEquals(0, $return);
        $output = '';
        foreach ($this->getTestHandler()->getRecords() as $record) {
            if ($record['level'] === Logger::ERROR) {
                $output = $record['message'];
            }
        }
        $config = json_decode(strrev((string) base64_decode($output)), true);
        self::assertIsArray($config);
        self::assertIsArray($config['parameters']);
        self::assertEquals('first', $config['parameters']['configKey1']);
        self::assertEquals('third', $config['parameters']['#configKey3']);
        self::assertEquals('fourth', $config['parameters']['#configKey4']);
        self::assertEquals('value1', $config['parameters']['rowKey1']);
        self::assertEquals('value3', $config['parameters']['#rowKey3']);
        self::assertEquals('value4', $config['parameters']['#rowKey4']);
    }

    public function testStoredConfigDecryptState(): void
    {
        // need to set this before hand so that the encryption wrappers are available
        $tokenInfo = $this->getClient()->verifyToken();
        $configData = [
            'parameters' => [
                'script' => [
                    'from pathlib import Path',
                    'import sys',
                    'import base64',
                    // [::-1] reverses string, because substr(base64(str)) may be equal to base64(substr(str)
                    'contents = Path("/data/in/state.json").read_text()[::-1]',
                    'print(base64.standard_b64encode(contents.encode("utf-8")).decode("utf-8"), file=sys.stderr)',
                ],
                'key1' => 'first',
                '#key3' => $this->getObjectEncryptor()->encryptForComponent(
                    'third',
                    'keboola.python-transformation',
                ),
                '#key4' => $this->getObjectEncryptor()->encryptForProject(
                    'fourth',
                    'keboola.python-transformation',
                    (string) $tokenInfo['owner']['id']
                ),
            ],
        ];
        $components = new Components($this->getClient());
        $configuration = new Configuration();
        $configuration->setComponentId('keboola.python-transformation');
        $configuration->setName('Test configuration');
        $configuration->setConfiguration($configData);
        $configuration->setState([
            'component' => [
                '#key5' => $this->getObjectEncryptor()->encryptForProject(
                    'fifth',
                    'keboola.python-transformation',
                    (string) $tokenInfo['owner']['id'],
                ),
                'key6' => 'sixth',
            ],
        ]);
        $configId = $components->addConfiguration($configuration)['id'];
        $jobData = [
            'componentId' => 'keboola.python-transformation',
            'mode' => 'run',
            'configId' => $configId,
        ];
        $command = $this->getCommand($jobData);

        $return = $command->run(new StringInput(''), new NullOutput());

        self::assertEquals(0, $return);
        $output = '';
        foreach ($this->getTestHandler()->getRecords() as $record) {
            if ($record['level'] === 400) {
                $output = $record['message'];
            }
        }
        $state = json_decode(strrev((string) base64_decode($output)), true);
        self::assertIsArray($state);
        self::assertEquals('fifth', $state['#key5']);
        self::assertEquals('sixth', $state['key6']);
    }
}

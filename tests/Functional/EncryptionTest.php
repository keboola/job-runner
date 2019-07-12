<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Keboola\ObjectEncryptor\Legacy\Wrapper\ComponentProjectWrapper;
use Keboola\ObjectEncryptor\Wrapper\ComponentWrapper;
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
        $this->getEncryptorFactory()->setComponentId('keboola.python-transformation');
        $this->getEncryptorFactory()->setProjectId($tokenInfo['owner']['id']);
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
                '#key2' => $this->getEncryptorFactory()->getEncryptor()->encrypt('second'),
                '#key3' => $this->getEncryptorFactory()->getEncryptor()->encrypt('third', ComponentWrapper::class),
                '#key4' => $this->getEncryptorFactory()->getEncryptor()->encrypt(
                    'fourth',
                    ComponentProjectWrapper::class
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
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'config' => $configId,
            ],
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
        self::assertEquals('first', $config['parameters']['key1']);
        self::assertEquals('second', $config['parameters']['#key2']);
        self::assertEquals('third', $config['parameters']['#key3']);
        self::assertEquals('fourth', $config['parameters']['#key4']);
    }

    public function testStoredConfigRowDecryptEncryptComponent(): void
    {
        // need to set this before hand so that the encryption wrappers are available
        $tokenInfo = $this->getClient()->verifyToken();
        $this->getEncryptorFactory()->setComponentId('keboola.python-transformation');
        $this->getEncryptorFactory()->setProjectId($tokenInfo['owner']['id']);
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
                '#configKey2' => $this->getEncryptorFactory()->getEncryptor()->encrypt('second'),
                '#configKey3' => $this->getEncryptorFactory()->getEncryptor()->encrypt(
                    'third',
                    ComponentWrapper::class
                ),
                '#configKey4' => $this->getEncryptorFactory()->getEncryptor()->encrypt(
                    'fourth',
                    ComponentProjectWrapper::class
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
                '#rowKey2' => $this->getEncryptorFactory()->getEncryptor()->encrypt('value2'),
                '#rowKey3' => $this->getEncryptorFactory()->getEncryptor()->encrypt('value3', ComponentWrapper::class),
                '#rowKey4' => $this->getEncryptorFactory()->getEncryptor()->encrypt(
                    'value4',
                    ComponentProjectWrapper::class
                ),
            ],
        ]);
        $components->addConfigurationRow($configRow);
        $jobData = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'config' => $configId,
            ],
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
        self::assertEquals('first', $config['parameters']['configKey1']);
        self::assertEquals('second', $config['parameters']['#configKey2']);
        self::assertEquals('third', $config['parameters']['#configKey3']);
        self::assertEquals('fourth', $config['parameters']['#configKey4']);
        self::assertEquals('value1', $config['parameters']['rowKey1']);
        self::assertEquals('value2', $config['parameters']['#rowKey2']);
        self::assertEquals('value3', $config['parameters']['#rowKey3']);
        self::assertEquals('value4', $config['parameters']['#rowKey4']);
    }

    public function testStoredConfigDecryptState(): void
    {
        // need to set this before hand so that the encryption wrappers are available
        $tokenInfo = $this->getClient()->verifyToken();
        $this->getEncryptorFactory()->setComponentId('keboola.python-transformation');
        $this->getEncryptorFactory()->setProjectId($tokenInfo['owner']['id']);
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
                '#key2' => $this->getEncryptorFactory()->getEncryptor()->encrypt('second'),
                '#key3' => $this->getEncryptorFactory()->getEncryptor()->encrypt('third', ComponentWrapper::class),
                '#key4' => $this->getEncryptorFactory()->getEncryptor()->encrypt(
                    'fourth',
                    ComponentProjectWrapper::class
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
                '#key5' => $this->getEncryptorFactory()->getEncryptor()->encrypt('fifth'),
                'key6' => 'sixth',
            ],
        ]);
        $configId = $components->addConfiguration($configuration)['id'];
        $jobData = [
            'params' => [
                'component' => 'keboola.python-transformation',
                'mode' => 'run',
                'config' => $configId,
            ],
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
        self::assertEquals('fifth', $state['#key5']);
        self::assertEquals('sixth', $state['key6']);
    }
}

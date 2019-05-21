<?php

declare(strict_types=1);

namespace App\Command;

use App\UsageFile;
use Keboola\DockerBundle\Docker\Runner;
use Keboola\DockerBundle\Monolog\ContainerLogger;
use Keboola\DockerBundle\Monolog\Handler\StorageApiHandler;
use Keboola\DockerBundle\Service\LoggersService;
use Keboola\JobQueueInternalClient\Client;
use Keboola\ObjectEncryptor\ObjectEncryptorFactory;
use Monolog\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends Command
{
    /**
     * The name of the command (the part after "bin/console")
     * @var string
     */
    protected static $defaultName = 'app:run';

    protected function configure(): void
    {
        $this
            // the short description shown while running "php bin/console list"
            ->setDescription('blabla')

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('more blablabla')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /*
        $queueClient = new Client('http://example.com', 'token');
        $job = $queueClient->getJobData('123');
        $encryptor = new ObjectEncryptorFactory('aws/keys', 'us-east-1', '', '');
        $client = new \Keboola\StorageApi\Client(['url' => 'http://example.com', 'token' => '123']);
        //$handler = new StorageApiHandler('test', $container);
        $logger = new Logger('name-1');
        $containerLogger = new ContainerLogger('name-2');
        $loggerService = new LoggersService($logger, $containerLogger, null);
        $runner = new Runner($encryptor, $client, $loggerService, 'oauthapiurl', []);
        $usageFile = new UsageFile();
        $runner->run($job, 'run', 'run', '123', $usageFile, 'row1');
        */
        $output->writeln('print something');
        return 0;
    }
}

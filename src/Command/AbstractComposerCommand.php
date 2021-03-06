<?php
/**
 * This file is part of the prooph/micro-cli.
 * (c) 2017-2018 prooph software GmbH <contact@prooph.de>
 * (c) 2017-2018 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Prooph\MicroCli\Command;

use Symfony\Component\Console\Helper\ProcessHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\OutputStyle;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

abstract class AbstractComposerCommand extends AbstractCommand
{
    private const DEFAULT_TIMEOUT = 0;
    private const DEFAULT_IDLE_TIMEOUT = 30;

    abstract protected function getComposerCommand(): string;

    protected function configure()
    {
        $this
            ->addArgument('service', InputArgument::OPTIONAL)
            ->addOption('all', '-a', InputOption::VALUE_NONE)
            ->addOption(
                'timeout',
                '-t',
                InputOption::VALUE_REQUIRED,
                'Sets the process timeout (max. runtime) per service in seconds',
                self::DEFAULT_TIMEOUT
            )
            ->addOption(
                'idle-timeout',
                '-i',
                InputOption::VALUE_REQUIRED,
                'Sets the process idle timeout (max. time since last output) per service in seconds',
                self::DEFAULT_IDLE_TIMEOUT
            )
            ->addOption(
                'docker-executable',
                null,
                InputOption::VALUE_REQUIRED,
                'Sets the path to docker executable'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $declaredPhpServices = $this->getDeclaredPhpServices();

        if (! $declaredPhpServices) {
            $io->warning('No php services declared in docker-compose.yml or no composer.json files found. Aborting');

            return 1;
        }

        $requestedServices = $this->getRequestedServices($input, $io, $declaredPhpServices);

        $timeout = (int) $input->getOption('timeout');
        $idleTimeout = (int) $input->getOption('idle-timeout');
        $dockerExecutable = $this->getDockerComposeExecutable($input);

        /** @var ProcessHelper $processHelper */
        $processHelper = $this->getHelper('process');

        $command = $this->getComposerCommand();

        foreach ($requestedServices as $service => $values) {
            $io->newLine(2);
            $io->section("Run `composer $command` for service $service");

            $process = new Process([
                $dockerExecutable,
                'run',
                '--rm',
                '--env',
                'COMPOSER_ALLOW_SUPERUSER=1',
                '--volume',
                $this->getServiceDirPath($service) . ':/app:rw',
                'prooph/composer:'. $values['php_version'],
                $command,
                '--no-interaction',
                '--no-suggest',
            ]);
            $process->setTimeout($timeout);
            $process->setIdleTimeout($idleTimeout);

            $processHelper->mustRun($output, $process, null, function ($type, $buffer) use ($io) {
                $io->write("$buffer");
            });
        }

        return 0;
    }

    private function getDeclaredPhpServices(): array
    {
        $phpServices = [];
        $dockerComposeConfig = $this->getDockerComposeConfig();

        foreach ($dockerComposeConfig['services'] as $service => $serviceConfig) {
            if (! isset($serviceConfig['image'])) {
                continue;
            }

            if (! preg_match('/^prooph\/php:([0-9\.]+)/', $serviceConfig['image'], $phpVersionMatches)) {
                continue;
            }

            if (! file_exists($this->getServiceDirPath($service) . '/composer.json')) {
                continue;
            }

            $phpServices[$service] = [
                'php_version' => $phpVersionMatches[1],
            ];
        }

        return $phpServices;
    }

    private function getRequestedServices(InputInterface $input, OutputStyle $io, array $declaredPhpServices): array
    {
        if ($input->getOption('all')) {
            return $declaredPhpServices;
        }

        $requestedService = $input->getArgument('service');

        if ($requestedService && ! array_key_exists($requestedService, $declaredPhpServices)) {
            $io->warning("Service with name '$requestedService' is not configured in docker-compose.yml yet.");
            $requestedService = null;
        }

        if (! $requestedService) {
            $requestedService = $io->choice('Select a service', array_keys($declaredPhpServices));
        }

        if (! array_key_exists($requestedService, $declaredPhpServices)) {
            throw new \RuntimeException('Invalid service name provided.');
        }

        return [$requestedService => $declaredPhpServices[$requestedService]];
    }

    private function getDockerComposeExecutable(InputInterface $input): string
    {
        static $executableFinder = null;

        $dockerExecutable = $input->getOption('docker-executable');

        if (null !== $dockerExecutable) {
            return $dockerExecutable;
        }

        if (null === $executableFinder) {
            $executableFinder = new ExecutableFinder();
        }

        $dockerComposePath = $executableFinder->find('docker', null);

        if (null === $dockerComposePath) {
            throw new \RuntimeException(
                'Could not detect docker executable. Please provide it with --docker-executable option.'
            );
        }

        return $dockerComposePath;
    }
}

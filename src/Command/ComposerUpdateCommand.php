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

class ComposerUpdateCommand extends AbstractComposerCommand
{
    protected function getComposerCommand(): string
    {
        return 'update';
    }

    protected function configure()
    {
        $this
            ->setName('micro:composer:update')
            ->setDescription('Updates composer dependencies for services');

        parent::configure();
    }
}

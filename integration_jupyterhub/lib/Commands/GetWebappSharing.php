<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Commands;

use OCA\Jupyter\AppInfo\Application;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GetWebappSharing extends Command
{
  public function __construct(private IConfig $config)
  {
    parent::__construct();
  }

  protected function configure(): void
  {
    $this
      ->setName('integration_jupyterhub:get-webapp-sharing')
      ->setDescription('Prints whether OCM webapp sharing is enabled.');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $enabled = $this->config->getAppValue(Application::APP_ID, 'webapp_sharing_enabled', 'no') === 'yes';
    $output->writeln($enabled ? 'enabled' : 'disabled');
    return 0;
  }
}

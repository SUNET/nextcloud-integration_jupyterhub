<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Commands;

use OCA\Jupyter\AppInfo\Application;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SetWebappSharing extends Command
{
  public function __construct(private IConfig $config)
  {
    parent::__construct();
  }

  protected function configure(): void
  {
    $this
      ->setName('integration_jupyterhub:set-webapp-sharing')
      ->setDescription('Enable or disable OCM webapp sharing (off by default).')
      ->addArgument('state', InputArgument::REQUIRED, 'enable | disable');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $state = strtolower((string)$input->getArgument('state'));
    $enabled = match ($state) {
      'enable', 'enabled', 'on', 'yes', 'true', '1' => true,
      'disable', 'disabled', 'off', 'no', 'false', '0' => false,
      default => null,
    };
    if ($enabled === null) {
      $output->writeln("<error>Unknown state '$state'. Use 'enable' or 'disable'.</error>");
      return 1;
    }
    $this->config->setAppValue(Application::APP_ID, 'webapp_sharing_enabled', $enabled ? 'yes' : 'no');
    $output->writeln('OCM webapp sharing is now ' . ($enabled ? 'enabled' : 'disabled') . '.');
    return 0;
  }
}

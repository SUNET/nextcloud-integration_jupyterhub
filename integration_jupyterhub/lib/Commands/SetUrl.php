<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Enrique Pérez Arnaud <eperez@emergya.com>, Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Commands;

use OCA\Jupyter\AppInfo\Application;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SetUrl extends Command
{
  private IConfig $config;

  public function __construct(IConfig $config)
  {
    parent::__construct();
    $this->config = $config;
  }

  protected function configure(): void
  {
    $this
      ->setName('integration_jupyterhub:set-url')
      ->setDescription('Sets the JupyterHub URL.')
      ->addArgument(
        'url',
        InputArgument::REQUIRED,
        'The URL for the JupyterHub instance.'
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $url = rtrim($input->getArgument('url'), '/');
    $this->config->setAppValue(Application::APP_ID, 'jupyter_url', $url);
    $output->writeln("Set <$url> as jupyter_url successfully.");
    return 0;
  }
}

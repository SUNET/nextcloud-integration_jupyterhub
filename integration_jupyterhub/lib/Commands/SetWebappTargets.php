<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Commands;

use OCA\Jupyter\AppInfo\Application;
use OCA\Jupyter\Federation\WebappCapabilityDiscovery;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SetWebappTargets extends Command
{
  public function __construct(private IConfig $config)
  {
    parent::__construct();
  }

  protected function configure(): void
  {
    $this
      ->setName('integration_jupyterhub:set-webapp-targets')
      ->setDescription('Set the view targets offered in webapp shares.')
      ->addArgument(
        'targets',
        InputArgument::IS_ARRAY | InputArgument::REQUIRED,
        'One or more of: iframe blank',
      );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    /** @var list<string> $args */
    $args = (array)$input->getArgument('targets');
    $targets = WebappCapabilityDiscovery::normaliseTargets($args);
    if ($targets === []) {
      $output->writeln('<error>No valid targets given. Use one or more of: iframe blank.</error>');
      return 1;
    }
    $this->config->setAppValue(Application::APP_ID, 'webapp_allowed_targets', implode(',', $targets));
    $output->writeln('Webapp share targets are now: ' . implode(' ', $targets) . '.');
    return 0;
  }
}

<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Commands;

use OCA\Jupyter\AppInfo\Application;
use OCA\Jupyter\Federation\WebappCapabilityDiscovery;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GetWebappTargets extends Command
{
  public function __construct(private IConfig $config)
  {
    parent::__construct();
  }

  protected function configure(): void
  {
    $this
      ->setName('integration_jupyterhub:get-webapp-targets')
      ->setDescription('Prints the view targets (iframe/redirect/blank) offered in webapp shares.');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $raw = $this->config->getAppValue(Application::APP_ID, 'webapp_allowed_targets', '');
    $targets = WebappCapabilityDiscovery::normaliseTargets(
      $raw === '' ? [] : explode(',', $raw),
    );
    if ($targets === []) {
      $targets = WebappCapabilityDiscovery::ALL_TARGETS;
    }
    $output->writeln(implode(' ', $targets));
    return 0;
  }
}

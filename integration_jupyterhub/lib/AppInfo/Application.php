<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Jupyter\Federation\WebappFederationProvider;
use OCA\Jupyter\Listener\CSPListener;
use OCA\Jupyter\Listener\LoadFilesScriptListener;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

class Application extends App implements IBootstrap
{
  public const APP_ID = 'integration_jupyterhub';
  public const WEBAPP_RESOURCE_TYPE = 'webapp';
  public const WEBAPP_DISPLAY_NAME = 'JupyterHub Notebook';

  public function __construct()
  {
    parent::__construct(self::APP_ID);
  }

  public function register(IRegistrationContext $context): void
  {
    $context->registerEventListener(AddContentSecurityPolicyEvent::class, CSPListener::class);
    $context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesScriptListener::class);
  }

  public function boot(IBootContext $context): void
  {
    $context->injectFn(function (ICloudFederationProviderManager $manager): void {
      try {
        $manager->addCloudFederationProvider(
          self::WEBAPP_RESOURCE_TYPE,
          self::WEBAPP_DISPLAY_NAME,
          fn () => \OC::$server->get(WebappFederationProvider::class),
        );
      } catch (\OCP\Federation\Exceptions\ProviderAlreadyExistsException $e) {
        // already registered
      }
    });
  }
}

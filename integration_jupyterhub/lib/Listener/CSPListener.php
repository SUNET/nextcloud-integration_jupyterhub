<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Listener;

use OCA\Jupyter\AppInfo\Application;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

class CSPListener implements IEventListener
{
  public function __construct(
    private IConfig $config,
  ) {
  }

  public function handle(Event $event): void
  {
    if (!($event instanceof AddContentSecurityPolicyEvent)) {
      return;
    }

    $jupyterUrl = $this->config->getAppValue(Application::APP_ID, 'jupyter_url');
    if (empty($jupyterUrl)) {
      return;
    }

    $parsed = parse_url($jupyterUrl);
    if (!isset($parsed['scheme'], $parsed['host'])) {
      return;
    }

    $origin = $parsed['scheme'] . '://' . $parsed['host'];

    $csp = new ContentSecurityPolicy();
    $csp->addAllowedConnectDomain($origin);
    $csp->addAllowedScriptDomain($origin);
    $csp->addAllowedFrameDomain($origin);

    $event->addPolicy($csp);
  }
}

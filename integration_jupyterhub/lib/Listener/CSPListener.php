<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
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
    // The JupyterHub iframe runs OAuth against this Nextcloud, which
    // means the iframe has to navigate to /index.php/apps/oauth2/authorize
    // on our own origin during sign-in. Without 'self' in frame-src,
    // the browser blocks that redirect with a CSP violation. NC's
    // CSP defaults to 'self' for most directives, but the moment we
    // create our own ContentSecurityPolicy we're explicitly defining
    // the frame-src list — so 'self' has to be re-added explicitly.
    $csp->addAllowedFrameDomain('\'self\'');

    $event->addPolicy($csp);
  }
}

<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Federation;

use OCA\Jupyter\AppInfo\Application;
use OCP\OCM\Exceptions\OCMProviderException;
use OCP\OCM\IOCMDiscoveryService;
use Psr\Log\LoggerInterface;

/**
 * Discover whether a remote OCM peer accepts webapp shares, and which
 * view targets it supports.
 *
 * Currently this is a STUB: the webapp-sharing OCM draft is still in
 * flux — the target (iframe / redirect / new-window) may live in
 * capabilities or be encoded into a per-resource protocol entry. We do
 * exercise the real {@see IOCMDiscoveryService} so peers without OCM
 * fail loudly, but until the spec stabilises we assume any reachable
 * OCM peer accepts the iframe target only.
 *
 * Extension point: replace the body of {@see discoverWebapp()} with
 * actual parsing of the discovered {@see \OCP\OCM\IOCMProvider}'s
 * resource types / capabilities.
 */
class DiscoveryService
{
  public function __construct(
    private IOCMDiscoveryService $discovery,
    private LoggerInterface $logger,
  ) {
  }

  /**
   * @throws WebappDiscoveryException
   */
  public function discoverWebapp(string $remote): WebappTargets
  {
    try {
      $provider = $this->discovery->discover($remote, false);
    } catch (OCMProviderException $e) {
      throw new WebappDiscoveryException(
        sprintf('Remote %s does not advertise an OCM provider: %s', $remote, $e->getMessage()),
        previous: $e,
      );
    }

    if (!$provider->isEnabled()) {
      throw new WebappDiscoveryException(sprintf('Remote %s has OCM disabled', $remote));
    }

    // TODO(ocm-webapp-draft): once the draft at
    // https://github.com/cs3org/OCM-API/blob/develop/work/webapps/webapp-sharing.md
    // lands, parse provider->getResourceTypes() / getCapabilities()
    // to determine which of iframe/redirect/new-window are accepted.
    // Until then, optimistically assume iframe is supported on any
    // OCM-capable peer.
    foreach ($provider->getResourceTypes() as $resource) {
      if ($resource->getName() === Application::WEBAPP_RESOURCE_TYPE) {
        $this->logger->debug('Remote {remote} advertises webapp resource type', ['remote' => $remote]);
        return WebappTargets::all();
      }
    }

    return WebappTargets::iframeOnly();
  }
}

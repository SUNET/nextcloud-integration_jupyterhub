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
 * Resolves a remote OCM peer's webapp view-target capabilities so the
 * sender can compute the wire-level `target` field as the intersection
 * of what both ends can do.
 *
 * Per OCM-API#368 the peer's discovery payload advertises
 * `webapp-receive` under the `folder` (or `file`) resource type, since
 * `webapp` is a protocol name, not a resource type. ocmremotewebapp
 * publishes:
 *
 *     "resourceTypes": [{
 *       "name": "folder",
 *       "shareTypes": ["user"],
 *       "protocols": {
 *         "webapp-receive": { "targets": ["blank", "redirect", "iframe"] }
 *       }
 *     }]
 *
 * `webapp-receive.targets` is the authoritative list of view targets
 * the receiver can render.
 */
class WebappCapabilityDiscovery
{
  /** @var list<string> All targets this app can produce as a sender or render as a receiver. */
  public const ALL_TARGETS = [
    WebappCloudFederationShare::TARGET_IFRAME,
    WebappCloudFederationShare::TARGET_REDIRECT,
    WebappCloudFederationShare::TARGET_BLANK,
  ];

  public function __construct(
    private IOCMDiscoveryService $discovery,
    private LoggerInterface $logger,
  ) {
  }

  /**
   * @return list<string> empty if remote doesn't advertise webapp
   */
  public function remoteSupportedTargets(string $remote): array
  {
    try {
      $provider = $this->discovery->discover($remote, false);
    } catch (OCMProviderException $e) {
      $this->logger->debug('OCM discovery failed for {remote}: {msg}', ['remote' => $remote, 'msg' => $e->getMessage()]);
      return [];
    }
    if (!$provider->isEnabled()) {
      return [];
    }
    foreach ($provider->getResourceTypes() as $resource) {
      // Per OCM-API#368, webapp-receive lives under the resource type of
      // the actual resource — folder (or file). We always share folders.
      if ($resource->getName() !== Application::WEBAPP_RESOURCE_TYPE
        && $resource->getName() !== 'file'
      ) {
        continue;
      }
      $protocols = $resource->getProtocols();
      $receive = $protocols['webapp-receive'] ?? null;
      if (is_array($receive) && isset($receive['targets']) && is_array($receive['targets'])) {
        return $this->filterKnownTargets($receive['targets']);
      }
    }
    return [];
  }

  /**
   * Compute the wire `target` array as the intersection of what the
   * user requested, what we (the sender) can produce, and what the
   * remote claims to accept. Preserves the order of preferred
   * targets.
   *
   * @param list<string> $requested user-preferred targets from the share dialog
   * @param list<string> $remoteSupported targets returned by {@see remoteSupportedTargets()}
   * @return list<string> empty list if there's no overlap
   */
  public function intersect(array $requested, array $remoteSupported): array
  {
    $out = [];
    foreach ($requested as $t) {
      if (in_array($t, self::ALL_TARGETS, true)
        && in_array($t, $remoteSupported, true)
        && !in_array($t, $out, true)
      ) {
        $out[] = $t;
      }
    }
    return $out;
  }

  /**
   * @param array<mixed> $targets
   * @return list<string>
   */
  private function filterKnownTargets(array $targets): array
  {
    $out = [];
    foreach ($targets as $t) {
      if (is_string($t) && in_array($t, self::ALL_TARGETS, true) && !in_array($t, $out, true)) {
        $out[] = $t;
      }
    }
    return $out;
  }
}

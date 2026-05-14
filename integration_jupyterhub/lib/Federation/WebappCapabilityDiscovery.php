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
 * The peer's discovery payload looks like (see
 * {@see \OCA\Jupyter\Listener\LocalOCMDiscoveryListener}):
 *
 *     "resourceTypes": [{
 *       "name": "webapp",
 *       "shareTypes": ["user"],
 *       "protocols": {
 *         "webapp": {},
 *         "webapp-receive": { "targets": ["blank", "redirect", "iframe"] }
 *       }
 *     }]
 *
 * The presence of `webapp` signals webapp-share support at all; the
 * `webapp-receive.targets` list declares which view targets the
 * receiver can render. We treat the latter as the authoritative list
 * and fall back to the full set only when a peer advertises `webapp`
 * but not yet `webapp-receive` (older shape).
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
      if ($resource->getName() !== Application::WEBAPP_RESOURCE_TYPE) {
        continue;
      }
      $protocols = $resource->getProtocols();
      $receive = $protocols['webapp-receive'] ?? null;
      // Real signal: webapp-receive.targets.
      if (is_array($receive) && isset($receive['targets']) && is_array($receive['targets'])) {
        return $this->filterKnownTargets($receive['targets']);
      }
      // Older shape: only `webapp` present, no per-target capability.
      // Assume the full set — we'll revise once peers advertise targets.
      if (array_key_exists('webapp', $protocols) || array_key_exists(Application::WEBAPP_RESOURCE_TYPE, $protocols)) {
        return self::ALL_TARGETS;
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

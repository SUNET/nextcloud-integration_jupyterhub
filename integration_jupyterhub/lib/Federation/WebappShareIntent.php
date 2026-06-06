<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Federation;

/**
 * Per-request hand-off between the webapp-share controller and the
 * {@see CloudFederationProviderManagerDecorator}.
 *
 * We can't read a webapp marker off the IShare's `attributes` JSON at
 * OCM-send time: NC's `FederatedShareProvider::addShareToDB()` does
 * not persist the attributes column, so any marker we set on the
 * in-memory IShare is gone by the time the decorator looks up the
 * share by provider id.
 *
 * Instead, the controller calls {@see announce()} just before invoking
 * {@see \OCP\Share\IManager::createShare()}; that call ultimately ends
 * up in our decorator's `sendShare()` / `sendCloudShare()`. The
 * decorator calls {@see pickup()} keyed on the outgoing share's
 * `shareWith` field — the same fully-qualified cloud-id the controller
 * announced — and receives the list of view targets to advertise on
 * the wire. The intent is consumed on pickup so subsequent shares to
 * the same recipient don't accidentally inherit it.
 *
 * Registered as a DI singleton (shared: true), so the controller and
 * decorator see the same instance within one HTTP request.
 */
class WebappShareIntent
{
  /** @var array<string, list<string>> normalized recipient cloud-id => list of targets */
  private array $pending = [];

  /**
   * @param list<string> $targets
   */
  public function announce(string $shareWith, array $targets): void
  {
    $this->pending[$this->normalize($shareWith)] = $targets;
  }

  /**
   * @return list<string>|null
   */
  public function pickup(string $shareWith): ?array
  {
    $key = $this->normalize($shareWith);
    if (!isset($this->pending[$key])) {
      return null;
    }
    $targets = $this->pending[$key];
    unset($this->pending[$key]);
    return $targets;
  }

  /**
   * Normalize a federated cloud-id for keying. NC rewrites the recipient
   * address between the controller's announce() and the decorator's
   * pickup() — notably prepending the `https://` scheme to the host part
   * (`bob@bob.example` -> `bob@https://bob.example`). Strip the scheme and
   * lowercase the host so both sides land on the same key.
   */
  private function normalize(string $shareWith): string
  {
    $at = strrpos($shareWith, '@');
    if ($at === false) {
      return strtolower($shareWith);
    }
    $user = substr($shareWith, 0, $at);
    $host = substr($shareWith, $at + 1);
    // Drop any leading scheme (http:// or https://) and a trailing slash.
    $host = preg_replace('#^https?://#i', '', $host) ?? $host;
    $host = rtrim($host, '/');
    return $user . '@' . strtolower($host);
  }
}

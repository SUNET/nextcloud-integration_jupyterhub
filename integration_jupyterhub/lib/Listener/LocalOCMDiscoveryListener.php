<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Listener;

use OCA\Jupyter\AppInfo\Application;
use OCA\Jupyter\Federation\WebappCloudFederationShare;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\OCM\Events\LocalOCMDiscoveryEvent;

/**
 * Advertises the {@see Application::WEBAPP_RESOURCE_TYPE} OCM resource
 * type on this instance's /.well-known/ocm discovery document.
 *
 * The wire shape we register is richer than NC's
 * `array<string, string>` type hint on
 * {@see \OCP\OCM\IOCMResource::setProtocols()} formally allows — we
 * pass nested arrays for the protocol values. PHP doesn't enforce the
 * docblock type, and NC's `OCMResource::jsonSerialize()` returns the
 * protocols map untouched, so the values round-trip through json_encode
 * as objects.
 *
 * The resulting discovery payload looks like:
 *
 *     "resourceTypes": [{
 *       "name": "webapp",
 *       "shareTypes": ["user"],
 *       "protocols": {
 *         "webapp": {},
 *         "webapp-receive": {
 *           "targets": ["blank", "redirect", "iframe"]
 *         }
 *       }
 *     }]
 *
 *  - `webapp` (empty object) signals: this instance speaks webapp shares.
 *  - `webapp-receive.targets` declares which view targets the local
 *    receiver can render, so a sender can compute the intersection
 *    against its own capabilities before deciding the wire-level
 *    `target` field on the outbound share.
 *
 * @implements IEventListener<LocalOCMDiscoveryEvent>
 */
class LocalOCMDiscoveryListener implements IEventListener
{
  public function handle(Event $event): void
  {
    if (!($event instanceof LocalOCMDiscoveryEvent)) {
      return;
    }
    $event->registerResourceType(
      Application::WEBAPP_RESOURCE_TYPE,
      ['user'],
      [
        // (object)[] forces the empty-object JSON shape ({}) instead of
        // the PHP-default [].
        Application::WEBAPP_RESOURCE_TYPE => (object)[],
        'webapp-receive' => [
          'targets' => [
            WebappCloudFederationShare::TARGET_BLANK,
            WebappCloudFederationShare::TARGET_REDIRECT,
            WebappCloudFederationShare::TARGET_IFRAME,
          ],
        ],
      ],
    );
  }
}

<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Listener;

use OCA\Jupyter\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\OCM\Events\LocalOCMDiscoveryEvent;

/**
 * Advertises the {@see Application::WEBAPP_RESOURCE_TYPE} OCM resource type
 * on this instance's /.well-known/ocm discovery document so remote peers
 * know we accept webapp shares.
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
      [Application::WEBAPP_RESOURCE_TYPE => Application::WEBAPP_PROTOCOL_PATH],
    );
  }
}

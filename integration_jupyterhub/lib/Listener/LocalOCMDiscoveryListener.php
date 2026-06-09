<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Listener;

use OCA\Jupyter\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\OCM\Events\LocalOCMDiscoveryEvent;

/**
 * @template-implements IEventListener<LocalOCMDiscoveryEvent>
 */
class LocalOCMDiscoveryListener implements IEventListener
{
  public function __construct(private IConfig $config)
  {
  }

  public function handle(Event $event): void
  {
    if (!($event instanceof LocalOCMDiscoveryEvent)) {
      return;
    }
    if ($this->config->getAppValue(Application::APP_ID, 'webapp_sharing_enabled', 'no') !== 'yes') {
      return;
    }

    // Send-only app: advertise the webapp sending role as an empty
    // object under the resource type we share (folder).
    $event->registerResourceType(
      Application::WEBAPP_RESOURCE_TYPE,
      ['user'],
      ['webapp' => (object)[]],
    );
  }
}

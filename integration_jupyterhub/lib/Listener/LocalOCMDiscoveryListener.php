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

    // We share folders as a multi-protocol OCM share carrying both webdav
    // (file access) and webapp (launch handoff). The receiver validates each
    // sent protocol against what we advertise for the resource type, so the
    // folder resource type must advertise webdav alongside the webapp sending
    // role; the webdav location mirrors the core `file` resource type.
    $event->registerResourceType(
      Application::WEBAPP_RESOURCE_TYPE,
      ['user'],
      ['webdav' => '/public.php/webdav/', 'webapp' => (object)[]],
    );
  }
}

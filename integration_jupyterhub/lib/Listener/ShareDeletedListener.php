<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Listener;

use OCA\Jupyter\AppInfo\Application;
use OCA\Jupyter\Federation\OCMHubBackChannel;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\IShare;

/**
 * Sender revoke path: when an outgoing federated share is deleted, ask the
 * hub to reap any notebook server it spawned. Decline is handled separately
 * (it removes the row without firing this event).
 *
 * @template-implements IEventListener<ShareDeletedEvent>
 */
class ShareDeletedListener implements IEventListener
{
  public function __construct(
    private IConfig $config,
    private OCMHubBackChannel $hubBackChannel,
  ) {
  }

  public function handle(Event $event): void
  {
    if (!$event instanceof ShareDeletedEvent) {
      return;
    }
    if ($this->config->getAppValue(Application::APP_ID, 'webapp_sharing_enabled', 'no') !== 'yes') {
      return;
    }
    $share = $event->getShare();
    if ($share->getShareType() !== IShare::TYPE_REMOTE) {
      return;
    }
    $this->hubBackChannel->close($share);
  }
}

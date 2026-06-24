<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Listener;

use OCA\Jupyter\AppInfo\Application;
use OCA\Jupyter\Federation\OCMHubBackChannel;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\IConfig;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Sender revoke path: when an outgoing federated share is deleted, send the
 * hub a Share Revocation Request so it reaps any notebook server it spawned.
 * Decline is handled separately (it removes the row without firing this
 * event).
 *
 * @template-implements IEventListener<ShareDeletedEvent>
 */
class ShareDeletedListener implements IEventListener
{
  public function __construct(
    private IConfig $config,
    private OCMHubBackChannel $hubBackChannel,
    private ICloudFederationProviderManager $federationManager,
    private ICloudFederationFactory $federationFactory,
    private LoggerInterface $logger,
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
    $this->hubBackChannel->revoke($share);
    $this->notifyWebappUnshared($share);
  }

  /**
   * Core's own SHARE_UNSHARED notification is hard-coded to resourceType
   * "file", which on the receiver only reaches the Files provider. Webapp
   * shares went out as "folder", so the receiver's webapp provider needs
   * its own notification or its share row is orphaned. Best-effort and
   * idempotent on the receiver (matched by remote providerId + secret).
   */
  private function notifyWebappUnshared(IShare $share): void
  {
    $sharedWith = (string)$share->getSharedWith();
    $at = strrpos($sharedWith, '@');
    if ($at === false || substr($sharedWith, $at + 1) === '') {
      return;
    }
    $remote = substr($sharedWith, $at + 1);
    try {
      $notification = $this->federationFactory->getCloudFederationNotification();
      $notification->setMessage('SHARE_UNSHARED', Application::WEBAPP_RESOURCE_TYPE, (string)$share->getId(), [
        'sharedSecret' => (string)$share->getToken(),
        'message' => 'share is no longer shared with you',
      ]);
      $this->federationManager->sendCloudNotification('https://' . $remote, $notification);
    } catch (\Throwable $e) {
      $this->logger->info('Webapp SHARE_UNSHARED notification to {remote} failed: {msg}', [
        'remote' => $remote,
        'msg' => $e->getMessage(),
      ]);
    }
  }
}

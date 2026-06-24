<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Federation;

use OCA\FederatedFileSharing\FederatedShareProvider;
use OCP\Federation\ICloudFederationProvider;
use OCP\Federation\ICloudFederationShare;
use Psr\Log\LoggerInterface;

/**
 * Wraps NC's "file" cloud federation provider so that when the recipient
 * declines an outgoing share (SHARE_DECLINED), we reap any notebook server
 * the hub spawned for it. Core removes the share row directly on decline
 * without firing ShareDeletedEvent, so this is the only hook for that path.
 * Every other call is a straight delegation.
 */
class FileShareDeclineReaper implements ICloudFederationProvider
{
  public function __construct(
    private ICloudFederationProvider $inner,
    private OCMHubBackChannel $hubBackChannel,
    private LoggerInterface $logger,
  ) {
  }

  public function notificationReceived($notificationType, $providerId, array $notification): array
  {
    if ($notificationType === 'SHARE_DECLINED') {
      // Resolve the share before inner removes its row.
      $this->reap((string)$providerId);
    }
    return $this->inner->notificationReceived($notificationType, $providerId, $notification);
  }

  private function reap(string $providerId): void
  {
    try {
      $share = \OCP\Server::get(FederatedShareProvider::class)->getShareById($providerId);
      $this->hubBackChannel->revoke($share);
    } catch (\Throwable $e) {
      $this->logger->debug('Decline reap skipped for share {id}: {msg}', [
        'id' => $providerId,
        'msg' => $e->getMessage(),
      ]);
    }
  }

  public function getShareType()
  {
    return $this->inner->getShareType();
  }

  public function shareReceived(ICloudFederationShare $share)
  {
    return $this->inner->shareReceived($share);
  }

  public function getSupportedShareTypes()
  {
    return $this->inner->getSupportedShareTypes();
  }
}

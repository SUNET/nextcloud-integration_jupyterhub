<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Federation;

use OC\Federation\CloudFederationShare;
use OCA\Jupyter\AppInfo\Application;
use OCP\Federation\Exceptions\ProviderCouldNotAddShareException;
use OCP\Federation\Exceptions\ProviderDoesNotExistsException;
use OCP\Federation\ICloudFederationProvider;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudFederationShare;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Handles inbound OCM shares whose resourceType is "webapp".
 *
 * Implements {@see ICloudFederationProvider} so NC's cloud_federation_api
 * routes inbound /ocm/shares requests with `resourceType: webapp` to us.
 *
 * The wire payload is a multi-protocol share built by
 * {@see WebappCloudFederationShare} — the share's `protocol` field is a
 * `{name: "multi", options: [...]}` envelope containing one or more
 * named entries. We unpack each entry and dispatch:
 *
 *   - `webdav` -> forward to NC's built-in `file` provider so the
 *                 standard external-mount machinery sets up a real
 *                 federated mount. We just synthesize a single-protocol
 *                 {@see CloudFederationShare} with resourceType=file.
 *   - `webapp` -> persist the launcher options via {@see WebappAppDataStore}
 *                 so the receiver UI can offer "Open in JupyterHub".
 *
 * Notification handling is symmetric: SHARE_ACCEPTED / SHARE_DECLINED
 * forward to the same wrapped file provider, which is the one that owns
 * the federated mount.
 */
class WebappCloudFederationProvider implements ICloudFederationProvider
{
  public function __construct(
    private ICloudFederationProviderManager $federationManager,
    private IUserManager $userManager,
    private WebappAppDataStore $store,
    private LoggerInterface $logger,
  ) {
  }

  public function getShareType(): string
  {
    return Application::WEBAPP_RESOURCE_TYPE;
  }

  public function getSupportedShareTypes(): array
  {
    return ['user'];
  }

  /**
   * @throws ProviderCouldNotAddShareException
   */
  public function shareReceived(ICloudFederationShare $share): string
  {
    if ($share->getResourceType() !== Application::WEBAPP_RESOURCE_TYPE) {
      throw new ProviderCouldNotAddShareException('Unsupported resource type', '', 400);
    }
    $localUid = $this->resolveLocalUser($share->getShareWith());
    if ($localUid === null) {
      throw new ProviderCouldNotAddShareException('Unknown recipient', '', 400);
    }

    $entries = $this->unpackMultiProtocol($share->getProtocol());
    $webdav = $entries['webdav'] ?? null;
    $webapp = $entries[Application::WEBAPP_RESOURCE_TYPE] ?? null;

    if ($webapp === null) {
      throw new ProviderCouldNotAddShareException('webapp protocol entry missing', '', 400);
    }

    // Hand the webdav entry to NC's file provider so the standard
    // federated mount appears in bob's "Shared with you" listing.
    // The webapp annotation we add afterwards is what powers the
    // "Open in JupyterHub" affordance in the sidebar.
    $fileShareId = null;
    if (is_array($webdav)) {
      $fileShareId = $this->forwardWebdavToFileProvider($share, $webdav);
    }

    $token = $share->getShareSecret() ?: bin2hex(random_bytes(8));
    $record = [
      'token' => $token,
      'localUid' => $localUid,
      'remoteOwner' => $share->getOwner(),
      'remoteSharedBy' => $share->getSharedBy(),
      'name' => $share->getResourceName(),
      'webapp' => $webapp,
      'fileShareId' => $fileShareId,
      'state' => 'pending',
      'createdAt' => time(),
    ];
    $this->store->put($localUid, $token, $record);
    if ($fileShareId !== null) {
      // Mirror the record under the local external-share id so the
      // sidebar UI can look us up by mounted node without round-tripping
      // back through the original OCM token.
      $this->store->put($localUid, 'file-share-' . $fileShareId, $record);
    }

    $this->logger->info('Stored inbound webapp share for {user}', ['user' => $localUid]);
    return $fileShareId ?? $token;
  }

  /**
   * @param array<string, mixed> $notification
   * @return array<string>
   */
  public function notificationReceived($notificationType, $providerId, array $notification): array
  {
    // Forward to NC's file provider so accept/decline updates the
    // underlying federated mount it owns.
    try {
      $file = $this->federationManager->getCloudFederationProvider('file');
    } catch (ProviderDoesNotExistsException) {
      return [];
    }
    return $file->notificationReceived($notificationType, $providerId, $notification);
  }

  /**
   * Build a single-protocol {@see CloudFederationShare} that looks like a
   * regular `file` OCM share, then hand it to NC's "file" provider via
   * {@see ICloudFederationProviderManager::getCloudFederationProvider()}.
   *
   * @param array<string, mixed> $webdavOptions
   * @return string|null provider-specific share id, or null on failure
   */
  private function forwardWebdavToFileProvider(ICloudFederationShare $original, array $webdavOptions): ?string
  {
    try {
      $file = $this->federationManager->getCloudFederationProvider('file');
    } catch (ProviderDoesNotExistsException $e) {
      $this->logger->warning('No file provider registered, skipping webdav mount: {msg}', ['msg' => $e->getMessage()]);
      return null;
    }

    $proxy = new CloudFederationShare(
      $original->getShareWith(),
      $original->getResourceName(),
      $original->getDescription(),
      $original->getProviderId(),
      $original->getOwner(),
      $original->getOwnerDisplayName(),
      $original->getSharedBy(),
      $original->getSharedByDisplayName(),
      $original->getShareType(),
      'file',
      (string)($webdavOptions['sharedSecret'] ?? ''),
    );
    $proxy->setProtocol(['name' => 'webdav', 'options' => $webdavOptions]);

    try {
      return (string)$file->shareReceived($proxy);
    } catch (\Throwable $e) {
      $this->logger->warning('Forwarding webdav portion to file provider failed: {msg}', [
        'msg' => $e->getMessage(),
        'exception' => $e,
      ]);
      return null;
    }
  }

  /**
   * Resolve the share recipient (`user@host`) to a local uid. NC's
   * cloud_federation_api already validates `@host` matches this server;
   * we just take the uid portion.
   */
  private function resolveLocalUser(string $shareWith): ?string
  {
    $at = strrpos($shareWith, '@');
    $uid = $at === false ? $shareWith : substr($shareWith, 0, $at);
    return $this->userManager->userExists($uid) ? $uid : null;
  }

  /**
   * Decode the protocol field into a name-keyed map of entries.
   *
   * Accepts the multi-protocol shape used by {@see WebappCloudFederationShare}:
   *
   *     {name: "multi", webdav: {...}, webapp: {...}}
   *
   * Also tolerates:
   *   - the new exchange-token webdav shape `{name: "webdav", webdav: {...}}`
   *   - the legacy single-protocol shape `{name: "webdav", options: {...}}`
   *
   * @param array<mixed> $protocol
   * @return array<string, array<string, mixed>>
   */
  private function unpackMultiProtocol(array $protocol): array
  {
    $entries = [];
    foreach ($protocol as $key => $value) {
      if ($key === 'name' || !is_array($value)) {
        continue;
      }
      $entries[(string)$key] = $value;
    }
    if ($entries !== []) {
      return $entries;
    }
    // Legacy single-protocol fallback.
    if (isset($protocol['name']) && is_array($protocol['options'] ?? null)) {
      $entries[(string)$protocol['name']] = $protocol['options'];
    }
    return $entries;
  }
}

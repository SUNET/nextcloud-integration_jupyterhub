<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Mikael Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Federation;

use OCA\Jupyter\AppInfo\Application;
use OCA\Jupyter\Db\WebappShare;
use OCA\Jupyter\Db\WebappShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Federation\Exceptions\ProviderCouldNotAddShareException;
use OCP\Federation\ICloudFederationProvider;
use OCP\Federation\ICloudFederationShare;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * Handles incoming OCM shares whose resourceType is "webapp".
 *
 * The wire format we expect (per the OCM webapp-sharing draft) is a
 * regular ICloudFederationShare whose protocol object carries a
 * "webapp" entry, e.g.:
 *
 *   protocol: {
 *     name: "webapp",
 *     options: {
 *       uri:          "https://sender/apps/integration_jupyterhub/ocm/open/<token>",
 *       sharedSecret: "...",
 *       viewMode:     "iframe" | "redirect" | "new-window",
 *       permissions:  ["read", "write"],
 *     }
 *   }
 *
 * The draft is unsettled; we accept the data on a best-effort basis and
 * persist whatever we can decode.
 */
class WebappFederationProvider implements ICloudFederationProvider
{
  public function __construct(
    private WebappShareMapper $mapper,
    private IUserManager $userManager,
    private ISecureRandom $random,
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

  public function shareReceived(ICloudFederationShare $share): string
  {
    if ($share->getShareType() !== 'user') {
      throw new ProviderCouldNotAddShareException('Only user shares are supported', '', 400);
    }
    if ($share->getResourceType() !== Application::WEBAPP_RESOURCE_TYPE) {
      throw new ProviderCouldNotAddShareException('Unsupported resource type', '', 400);
    }

    $shareWith = $share->getShareWith();
    $localUid = $this->resolveLocalUser($shareWith);
    if ($localUid === null) {
      throw new ProviderCouldNotAddShareException('Unknown recipient', '', 400);
    }

    $protocol = $share->getProtocol();
    $options = $this->extractWebappOptions($protocol);

    $entity = new WebappShare();
    $entity->setDirection(WebappShare::DIRECTION_IN);
    $entity->setToken($this->random->generate(32, ISecureRandom::CHAR_ALPHANUMERIC));
    $entity->setRemoteUri($options['uri'] ?? null);
    $entity->setRemoteUser($share->getSharedBy() ?: $share->getOwner());
    $entity->setLocalUser($localUid);
    $entity->setSharedSecret($options['sharedSecret'] ?? $share->getShareSecret());
    $entity->setViewMode($this->normaliseViewMode($options['viewMode'] ?? WebappShare::VIEW_IFRAME));
    $entity->setPermissions(is_array($options['permissions'] ?? null)
      ? implode(',', $options['permissions'])
      : (string)($options['permissions'] ?? 'read'));
    $entity->setName($share->getResourceName() ?: ($options['name'] ?? 'Webapp share'));
    $entity->setMimeType($options['mimeType'] ?? 'application/vnd.jupyter');
    $entity->setState('pending');
    $entity->setCreatedAt(time());

    $saved = $this->mapper->insert($entity);
    $this->logger->info('Received OCM webapp share id={id} for {user}', [
      'id' => $saved->getId(),
      'user' => $localUid,
    ]);
    return (string)$saved->getId();
  }

  public function notificationReceived(string $notificationType, string $providerId, array $notification): array
  {
    try {
      $entity = $this->mapper->findByToken($providerId);
    } catch (DoesNotExistException $e) {
      return [];
    }

    switch ($notificationType) {
      case 'SHARE_ACCEPTED':
        $entity->setState('accepted');
        $entity->setAcceptedAt(time());
        $this->mapper->update($entity);
        break;
      case 'SHARE_DECLINED':
      case 'SHARE_UNSHARED':
        $entity->setState('declined');
        $this->mapper->update($entity);
        break;
      default:
        $this->logger->debug('Ignoring webapp OCM notification {type}', ['type' => $notificationType]);
    }
    return [];
  }

  private function resolveLocalUser(string $shareWith): ?string
  {
    // shareWith comes in as 'uid@host' — strip the host part.
    $uid = $shareWith;
    if (str_contains($shareWith, '@')) {
      $uid = substr($shareWith, 0, strrpos($shareWith, '@'));
    }
    return $this->userManager->userExists($uid) ? $uid : null;
  }

  /**
   * The OCM protocol object can be either a v1.0 flat map ("name" => "options")
   * or a v1.1+ list of named entries. Try both shapes.
   *
   * @param array<mixed> $protocol
   * @return array<string,mixed>
   */
  private function extractWebappOptions(array $protocol): array
  {
    if (isset($protocol['name']) && $protocol['name'] === 'webapp' && is_array($protocol['options'] ?? null)) {
      return $protocol['options'];
    }
    if (isset($protocol['webapp']) && is_array($protocol['webapp'])) {
      return $protocol['webapp'];
    }
    foreach ($protocol as $entry) {
      if (is_array($entry) && ($entry['name'] ?? null) === 'webapp' && is_array($entry['options'] ?? null)) {
        return $entry['options'];
      }
    }
    return [];
  }

  private function normaliseViewMode(string $mode): string
  {
    return match (strtolower($mode)) {
      'iframe' => WebappShare::VIEW_IFRAME,
      'redirect' => WebappShare::VIEW_REDIRECT,
      'new-window', 'newwindow', 'new_window' => WebappShare::VIEW_NEW_WINDOW,
      default => WebappShare::VIEW_IFRAME,
    };
  }
}

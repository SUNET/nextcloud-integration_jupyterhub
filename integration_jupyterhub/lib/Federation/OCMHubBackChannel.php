<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Federation;

use OC\OCM\OCMSignatoryManager;
use OC\OCM\Rfc9421SignatoryManager;
use OCA\Jupyter\AppInfo\Application;
use OCP\Federation\ICloudIdManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\Security\Signature\ISignatureManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Pushes a webapp share envelope to the paired JupyterHub's
 * /services/ocm/shares back channel. Same RFC 9421 "ocm" signature
 * the receiver-bound OCM share uses; sharedSecret is stripped from
 * each protocol entry — the hub never receives any OCM secret. The
 * hub keys the share by (sender domain, providerId), which the
 * access token's `client_id` claim must equal.
 *
 * `providerId` here is the row id of the refresh token (`oc_authtoken.id`
 * — the entity behind the share's sharedSecret). NC core's
 * cloud_federation_api TokenController hard-codes JWT `client_id` to that
 * same row id (see `(string)$token->getId()` in TokenController::accessToken),
 * so this is the only identifier the receiver's hub will ever look up shares
 * by. `$share->getProviderId()` and `$share->getId()` (the federated share
 * row id) do NOT match what NC mints into the JWT — using either of those
 * gives every `/services/ocm/open` a 404 "no share record".
 */
class OCMHubBackChannel
{
  public function __construct(
    private IClientService $clientService,
    private ISignatureManager $signatureManager,
    private OCMSignatoryManager $signatoryManager,
    private ICloudIdManager $cloudIdManager,
    private IConfig $config,
    private LoggerInterface $logger,
  ) {
  }

  /**
   * @throws OCMBackChannelException if the hub does not acknowledge the
   *   envelope; the caller must then abort the outbound OCM share.
   */
  public function push(WebappCloudFederationShare $share): void
  {
    $hubBase = rtrim((string)$this->config->getAppValue(Application::APP_ID, 'jupyter_url', ''), '/');
    if ($hubBase === '') {
      // No hub configured at all — the webapp share cannot function, so
      // this is a hard failure rather than a silent skip.
      throw new OCMBackChannelException('JupyterHub URL is not configured');
    }

    $protocol = $share->getProtocol();
    // Resolve providerId from the sharedSecret BEFORE stripping it: the hub
    // keys shares by the refresh-token row id (= JWT client_id), and the
    // sharedSecret is the only handle we have to that token here.
    $sharedSecret = (string)($protocol['webdav']['sharedSecret']
      ?? $protocol['webapp']['sharedSecret']
      ?? '');
    $providerId = $this->resolveTokenId($sharedSecret);
    if ($providerId === null) {
      throw new OCMBackChannelException(
        'Cannot resolve OCM share token row id for back-channel push'
      );
    }
    foreach (['webdav', 'webapp'] as $entry) {
      if (isset($protocol[$entry]['sharedSecret'])) {
        unset($protocol[$entry]['sharedSecret']);
      }
    }

    $payload = [
      'shareWith' => $share->getShareWith(),
      'name' => $share->getResourceName(),
      'description' => $share->getDescription(),
      'providerId' => $providerId,
      'owner' => $share->getOwner(),
      'ownerDisplayName' => $share->getOwnerDisplayName(),
      'sender' => $share->getSharedBy() ?: $share->getOwner(),
      'senderDisplayName' => $share->getSharedByDisplayName() ?: $share->getOwnerDisplayName(),
      'shareType' => $share->getShareType(),
      'resourceType' => $share->getResourceType(),
      'protocol' => $protocol,
    ];
    $body = (string)json_encode($payload, JSON_UNESCAPED_SLASHES);
    $url = $hubBase . '/services/ocm/shares';

    try {
      $signed = $this->signatureManager->signOutgoingRequestIClientPayload(
        new Rfc9421SignatoryManager($this->signatoryManager),
        ['body' => $body, 'headers' => ['Content-Type' => 'application/json']],
        'POST',
        $url,
      );
      $response = $this->clientService->newClient()->post($url, [
        'headers' => $signed['headers'],
        'body' => $signed['body'],
        'timeout' => 15,
      ]);
    } catch (OCMBackChannelException $e) {
      throw $e;
    } catch (\Throwable $e) {
      $this->logger->warning('OCM back channel push failed', ['exception' => $e, 'url' => $url]);
      throw new OCMBackChannelException('Back-channel push to JupyterHub failed', 0, $e);
    }

    $status = $response->getStatusCode();
    if ($status < 200 || $status >= 300) {
      $this->logger->warning('OCM back channel push rejected', [
        'url' => $url,
        'status' => $status,
        'body' => substr((string)$response->getBody(), 0, 500),
      ]);
      throw new OCMBackChannelException(sprintf('JupyterHub rejected the share (HTTP %d)', $status));
    }

    $this->logger->debug('OCM back channel pushed', [
      'url' => $url,
      'status' => $status,
      'providerId' => $providerId,
    ]);
  }

  /**
   * Tell the hub to reap the notebook server it spawned for this share.
   * Best-effort: unlike push() a failure must never block the unshare, so
   * everything here only logs. The hub matches the share by (sender,
   * providerId) and is idempotent, so a non-webapp share simply gets "gone".
   */
  public function close(IShare $share): void
  {
    $hubBase = rtrim((string)$this->config->getAppValue(Application::APP_ID, 'jupyter_url', ''), '/');
    if ($hubBase === '') {
      return;
    }

    // Same key push() registered the share under: the refresh-token row id
    // (= JWT client_id). $share->getToken() is the share's sharedSecret, which
    // is the refresh-token string the receiver later exchanges at the OCM
    // token endpoint.
    $providerId = $this->resolveTokenId((string)$share->getToken());
    if ($providerId === null) {
      // Best-effort reap — without an id the hub can't locate the share. Bail
      // quietly: a stale notebook server will hit the 24h sweep instead.
      $this->logger->info('OCM hub reap skipped: no refresh-token row id for share {sid}', [
        'sid' => $share->getId(),
      ]);
      return;
    }

    $sender = $this->cloudIdManager->getCloudId($share->getShareOwner(), null)->getId();
    $body = (string)json_encode(['sender' => $sender, 'providerId' => $providerId], JSON_UNESCAPED_SLASHES);
    $url = $hubBase . '/services/ocm/close';

    try {
      $signed = $this->signatureManager->signOutgoingRequestIClientPayload(
        new Rfc9421SignatoryManager($this->signatoryManager),
        ['body' => $body, 'headers' => ['Content-Type' => 'application/json']],
        'POST',
        $url,
      );
      $this->clientService->newClient()->post($url, [
        'headers' => $signed['headers'],
        'body' => $signed['body'],
        'timeout' => 10,
      ]);
    } catch (\Throwable $e) {
      $this->logger->info('OCM hub reap failed for providerId {pid}: {msg}', [
        'pid' => $providerId,
        'msg' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Look up the `oc_authtoken` row id for a sharedSecret (refresh token).
   * Returns null if the token is unknown or the provider is unavailable;
   * callers decide whether that's fatal.
   *
   * Uses a service locator rather than constructor injection: the token
   * provider lives in the internal `\OC\…` namespace, and `WebappShareController`
   * already accesses it the same way.
   */
  private function resolveTokenId(string $sharedSecret): ?string
  {
    if ($sharedSecret === '') {
      return null;
    }
    try {
      /** @var \OC\Authentication\Token\IProvider $tp */
      $tp = \OCP\Server::get(\OC\Authentication\Token\IProvider::class);
      return (string)$tp->getToken($sharedSecret)->getId();
    } catch (\Throwable $e) {
      $this->logger->warning('Could not resolve OCM share token row id: {msg}', [
        'msg' => $e->getMessage(),
      ]);
      return null;
    }
  }
}

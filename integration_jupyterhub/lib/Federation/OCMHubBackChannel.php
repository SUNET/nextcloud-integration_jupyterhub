<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Jupyter\Federation;

use OC\Authentication\Token\IProvider;
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
 * the receiver-bound OCM share uses; sharedSecret in each protocol
 * entry is replaced by clientId (the id of the PublicKeyToken NC
 * minted for the share at FederatedShareProvider::createFederatedShare).
 */
class OCMHubBackChannel
{
  public function __construct(
    private IClientService $clientService,
    private ISignatureManager $signatureManager,
    private OCMSignatoryManager $signatoryManager,
    private IProvider $tokenProvider,
    private ICloudIdManager $cloudIdManager,
    private IConfig $config,
    private LoggerInterface $logger,
  ) {
  }

  /**
   * @throws OCMBackChannelException if the hub does not acknowledge the
   *   envelope; the caller must then abort the outbound OCM share.
   */
  public function push(WebappCloudFederationShare $share, string $sharedSecret): void
  {
    $hubBase = rtrim((string)$this->config->getAppValue(Application::APP_ID, 'jupyter_url', ''), '/');
    if ($hubBase === '') {
      // No hub configured at all — the webapp share cannot function, so
      // this is a hard failure rather than a silent skip.
      throw new OCMBackChannelException('JupyterHub URL is not configured');
    }

    try {
      $token = $this->tokenProvider->getToken($sharedSecret);
    } catch (\Throwable $e) {
      throw new OCMBackChannelException('Cannot resolve clientId from sharedSecret', 0, $e);
    }
    $clientId = (string)$token->getId();

    $protocol = $share->getProtocol();
    foreach (['webdav', 'webapp'] as $entry) {
      if (isset($protocol[$entry]['sharedSecret'])) {
        unset($protocol[$entry]['sharedSecret']);
      }
    }
    $protocol['webapp']['clientId'] = $clientId;

    $payload = [
      'shareWith' => $share->getShareWith(),
      'name' => $share->getResourceName(),
      'description' => $share->getDescription(),
      'providerId' => $share->getProviderId(),
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
      'providerId' => $share->getProviderId(),
      'clientId' => $clientId,
    ]);
  }

  /**
   * Tell the hub to reap the notebook server it spawned for this share.
   * Best-effort: unlike push() a failure must never block the unshare, so
   * everything here only logs. The hub matches the share by (sender, clientId)
   * and is idempotent, so a non-webapp share simply gets "gone".
   */
  public function close(IShare $share): void
  {
    $hubBase = rtrim((string)$this->config->getAppValue(Application::APP_ID, 'jupyter_url', ''), '/');
    if ($hubBase === '') {
      return;
    }

    try {
      // clientId = id of the PublicKeyToken whose value is the share secret,
      // the same key push() registered the share under.
      $clientId = (string)$this->tokenProvider->getToken($share->getToken())->getId();
    } catch (\Throwable $e) {
      // No resolvable token => nothing we registered with the hub.
      return;
    }

    $sender = $this->cloudIdManager->getCloudId($share->getShareOwner(), null)->getId();
    $body = (string)json_encode(['sender' => $sender, 'clientId' => $clientId], JSON_UNESCAPED_SLASHES);
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
      $this->logger->info('OCM hub reap failed for clientId {cid}: {msg}', [
        'cid' => $clientId,
        'msg' => $e->getMessage(),
      ]);
    }
  }
}
